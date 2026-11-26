<?php

use App\Modules\Core\Rbac\Actions\GrantEntityCollaboratorAction;
use App\Modules\Core\Rbac\Actions\RevokeEntityCollaboratorAction;
use App\Modules\Core\Rbac\Enums\CollaboratorAccessLevel;
use App\Modules\Core\Rbac\Enums\CollaboratorRoleLabel;
use App\Modules\Core\Rbac\Enums\RoleEnum;
use App\Modules\Core\Users\Actions\RebuildUserHierarchyClosureAction;
use App\Modules\Core\Users\Enums\UserStatusEnum;
use App\Modules\Core\Users\Models\Department;
use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Companies\Models\Company;
use App\Modules\Crm\Deals\Models\Deal;
use App\Modules\Crm\Pipelines\Models\Pipeline;
use App\Modules\Projects\Handoffs\Enums\DealHandoffStatusEnum;
use App\Modules\Projects\Handoffs\Models\DealHandoff;
use App\Modules\Projects\Handoffs\Policies\DealHandoffPolicy;
use App\Modules\Projects\Projects\Enums\ProjectHealthEnum;
use App\Modules\Projects\Projects\Enums\ProjectStageCategoryEnum;
use App\Modules\Projects\Projects\Enums\ProjectStatusEnum;
use App\Modules\Projects\Projects\Models\Project;
use App\Modules\Projects\Projects\Models\ProjectStage;
use App\Modules\Projects\Projects\Policies\ProjectPolicy;
use App\Modules\Projects\Tasks\Enums\TaskPriorityEnum;
use App\Modules\Projects\Tasks\Enums\TaskStatusEnum;
use App\Modules\Projects\Tasks\Models\Task;
use App\Modules\Projects\Tasks\Policies\TaskPolicy;
use App\Modules\Projects\Templates\Enums\TemplateAssigneeRoleHintEnum;
use App\Modules\Projects\Templates\Models\ProjectTemplate;
use App\Modules\Projects\Templates\Models\ProjectTemplateTask;
use App\Modules\Projects\Templates\Policies\ProjectTemplatePolicy;
use Database\Seeders\CrmDefaultsSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ProjectDefaultsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

function createProjectsTestUser(
    Department $department,
    string $code,
    string $email,
    RoleEnum $role,
    ?User $manager = null,
): User {
    $user = User::query()->create([
        'employee_code' => $code,
        'name' => $code,
        'email' => $email,
        'department_id' => $department->id,
        'manager_id' => $manager?->id,
        'status' => UserStatusEnum::Active,
    ]);

    $user->assignRole($role->value);
    app(RebuildUserHierarchyClosureAction::class)->execute($user);

    return $user;
}

beforeEach(function (): void {
    Cache::flush();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesPermissionsSeeder::class);
});

afterEach(function (): void {
    Cache::flush();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('creates all 8 PostgreSQL 17 Projects tables, project_code_seq, partial/GIN/unique indexes, morphMap, and policies', function (): void {
    $tables = [
        'project_templates',
        'project_template_tasks',
        'deal_handoffs',
        'projects',
        'deal_project',
        'project_stages',
        'tasks',
        'task_watchers',
    ];

    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeTrue("Expected table [{$table}] to exist.");
    }

    $sequenceExists = DB::selectOne("SELECT sequencename FROM pg_sequences WHERE sequencename = 'project_code_seq'");
    expect($sequenceExists)->not->toBeNull();

    $nextVal = DB::selectOne("SELECT nextval('project_code_seq') AS seq");
    expect((int) $nextVal->seq)->toBeGreaterThanOrEqual(1);

    $handoffIndexes = collect(DB::select("SELECT indexname, indexdef FROM pg_indexes WHERE tablename = 'deal_handoffs'"))
        ->keyBy('indexname');
    expect($handoffIndexes->keys()->all())->toContain(
        'idx_handoffs_queue',
        'idx_handoffs_deal',
        'uq_deal_handoffs_active_deal',
    )
        ->and($handoffIndexes->get('idx_handoffs_queue')->indexdef)->toContain('submitted_at DESC')
        ->and($handoffIndexes->get('uq_deal_handoffs_active_deal')->indexdef)->toContain('CREATE UNIQUE INDEX', 'WHERE');

    $projectIndexes = collect(DB::select("SELECT indexname, indexdef FROM pg_indexes WHERE tablename = 'projects'"))
        ->keyBy('indexname');
    expect($projectIndexes->keys()->all())->toContain(
        'idx_projects_owner',
        'idx_projects_am',
        'idx_projects_company',
        'idx_projects_cf',
    )
        ->and($projectIndexes->get('idx_projects_owner')->indexdef)->toContain('WHERE (deleted_at IS NULL)')
        ->and($projectIndexes->get('idx_projects_am')->indexdef)->toContain('WHERE (deleted_at IS NULL)')
        ->and($projectIndexes->get('idx_projects_cf')->indexdef)->toContain('USING gin', 'jsonb_path_ops');

    $stageIndexes = collect(DB::select("SELECT indexname FROM pg_indexes WHERE tablename = 'project_stages'"))
        ->pluck('indexname')
        ->all();
    expect($stageIndexes)->toContain('idx_project_stages_order');

    $taskIndexes = collect(DB::select("SELECT indexname, indexdef FROM pg_indexes WHERE tablename = 'tasks'"))
        ->keyBy('indexname');
    expect($taskIndexes->keys()->all())->toContain(
        'idx_tasks_board',
        'idx_tasks_my_work',
        'idx_tasks_linkable',
    )
        ->and($taskIndexes->get('idx_tasks_board')->indexdef)->toContain('WHERE (deleted_at IS NULL)')
        ->and($taskIndexes->get('idx_tasks_my_work')->indexdef)->toContain('WHERE (deleted_at IS NULL)');

    expect(Relation::getMorphedModel('project_template'))->toBe(ProjectTemplate::class)
        ->and(Relation::getMorphedModel('deal_handoff'))->toBe(DealHandoff::class)
        ->and(Relation::getMorphedModel('project'))->toBe(Project::class)
        ->and(Relation::getMorphedModel('project_stage'))->toBe(ProjectStage::class)
        ->and(Relation::getMorphedModel('task'))->toBe(Task::class)
        ->and(Gate::getPolicyFor(ProjectTemplate::class))->toBeInstanceOf(ProjectTemplatePolicy::class)
        ->and(Gate::getPolicyFor(DealHandoff::class))->toBeInstanceOf(DealHandoffPolicy::class)
        ->and(Gate::getPolicyFor(Project::class))->toBeInstanceOf(ProjectPolicy::class)
        ->and(Gate::getPolicyFor(Task::class))->toBeInstanceOf(TaskPolicy::class);

    $this->seed(CrmDefaultsSeeder::class);
    $dept = Department::query()->create(['name' => 'Delivery', 'slug' => 'projects-schema-delivery']);
    $sales = createProjectsTestUser($dept, 'EMP-PRJ-S1', 'sales.schema@acme.internal', RoleEnum::Sales);
    $pm = createProjectsTestUser($dept, 'EMP-PRJ-PM1', 'pm.schema@acme.internal', RoleEnum::ProjectManager);
    $am = createProjectsTestUser($dept, 'EMP-PRJ-AM1', 'am.schema@acme.internal', RoleEnum::AccountManager);

    $template = ProjectTemplate::query()->create([
        'name' => 'Custom Implementation',
        'department_id' => $dept->id,
        'default_stages' => [
            ['key' => 'backlog', 'name' => 'Backlog', 'category' => ProjectStageCategoryEnum::Backlog->value, 'sort_order' => 1, 'wip_limit' => null],
        ],
        'description' => 'Implementation template',
        'is_active' => true,
        'created_by_id' => $pm->id,
    ]);
    $parentTplTask = ProjectTemplateTask::query()->create([
        'project_template_id' => $template->id,
        'stage_key' => 'backlog',
        'title' => 'Parent template task',
        'relative_due_days' => 2,
        'assignee_role_hint' => TemplateAssigneeRoleHintEnum::Pm,
        'sort_order' => 1,
    ]);
    $childTplTask = ProjectTemplateTask::query()->create([
        'project_template_id' => $template->id,
        'parent_template_task_id' => $parentTplTask->id,
        'stage_key' => 'backlog',
        'title' => 'Child template task',
        'relative_due_days' => 3,
        'assignee_role_hint' => TemplateAssigneeRoleHintEnum::Am,
        'sort_order' => 2,
    ]);

    expect(Str::isUuid($template->id))->toBeTrue()
        ->and($template->tasks()->count())->toBe(2)
        ->and($childTplTask->parent?->id)->toBe($parentTplTask->id);

    $pipeline = Pipeline::query()->where('slug', 'new-business')->firstOrFail();
    $dealStage = $pipeline->stages()->orderBy('sort_order')->firstOrFail();
    $company = Company::query()->create(['name' => 'Handoff Client', 'owner_id' => $sales->id]);
    $deal = Deal::query()->create([
        'company_id' => $company->id,
        'pipeline_id' => $pipeline->id,
        'stage_id' => $dealStage->id,
        'owner_id' => $sales->id,
        'title' => 'Client Deal',
        'stage_entered_at' => now(),
    ]);

    $handoff = DealHandoff::query()->create([
        'deal_id' => $deal->id,
        'company_id' => $company->id,
        'submitted_by_id' => $sales->id,
        'assigned_pm_id' => $pm->id,
        'assigned_am_id' => $am->id,
        'project_template_id' => $template->id,
        'status' => DealHandoffStatusEnum::PendingReview,
        'scope_notes_json' => ['type' => 'doc', 'content' => []],
        'handoff_checklist' => [['label' => 'Signed SOW', 'checked' => true]],
        'submitted_at' => now(),
    ]);

    // Partial unique index rejects second active handoff on same deal_id
    expect(fn () => DB::transaction(static fn () => DealHandoff::query()->create([
        'deal_id' => $deal->id,
        'company_id' => $company->id,
        'submitted_by_id' => $sales->id,
        'status' => DealHandoffStatusEnum::PendingReview,
        'scope_notes_json' => ['type' => 'doc', 'content' => []],
        'submitted_at' => now(),
    ])))->toThrow(QueryException::class);

    $handoff->update(['status' => DealHandoffStatusEnum::ChangesRequested]);
    expect(fn () => DB::transaction(static fn () => DealHandoff::query()->create([
        'deal_id' => $deal->id,
        'company_id' => $company->id,
        'submitted_by_id' => $sales->id,
        'status' => DealHandoffStatusEnum::Approved,
        'scope_notes_json' => ['type' => 'doc', 'content' => []],
        'submitted_at' => now(),
    ])))->toThrow(QueryException::class);

    // Rejected allows a new active handoff submission for the same deal_id
    $handoff->update(['status' => DealHandoffStatusEnum::Rejected]);
    $reSubmittedHandoff = DealHandoff::query()->create([
        'deal_id' => $deal->id,
        'company_id' => $company->id,
        'submitted_by_id' => $sales->id,
        'status' => DealHandoffStatusEnum::PendingReview,
        'scope_notes_json' => ['type' => 'doc', 'content' => []],
        'submitted_at' => now(),
    ]);
    expect($reSubmittedHandoff->status)->toBe(DealHandoffStatusEnum::PendingReview);

    $project = Project::query()->create([
        'code' => 'PRJ-2026-0001',
        'company_id' => $company->id,
        'primary_deal_id' => $deal->id,
        'deal_handoff_id' => $reSubmittedHandoff->id,
        'owner_id' => $pm->id,
        'account_manager_id' => $am->id,
        'department_id' => $dept->id,
        'name' => 'Client Onboarding',
        'status' => ProjectStatusEnum::Active,
        'health' => ProjectHealthEnum::OnTrack,
        'custom_fields' => ['tier' => 'enterprise'],
    ]);
    $project->deals()->attach($deal->id);

    expect($project->deals()->pluck('deals.id')->all())->toBe([$deal->id])
        ->and(Project::query()->whereRaw("custom_fields @> '{\"tier\": \"enterprise\"}'::jsonb")->pluck('id')->all())->toBe([$project->id]);

    // Unique constraint on projects.deal_handoff_id & projects.code
    expect(fn () => DB::transaction(static fn () => Project::query()->create([
        'code' => 'PRJ-2026-0002',
        'deal_handoff_id' => $reSubmittedHandoff->id,
        'owner_id' => $pm->id,
        'department_id' => $dept->id,
        'name' => 'Duplicate Handoff Project',
    ])))->toThrow(QueryException::class);

    expect(fn () => DB::transaction(static fn () => Project::query()->create([
        'code' => 'PRJ-2026-0001',
        'owner_id' => $pm->id,
        'department_id' => $dept->id,
        'name' => 'Duplicate Code Project',
    ])))->toThrow(QueryException::class);

    $stage = ProjectStage::query()->create([
        'project_id' => $project->id,
        'name' => 'To Do',
        'category' => ProjectStageCategoryEnum::Todo,
        'sort_order' => 1,
        'wip_limit' => 5,
    ]);

    $parentTask = Task::query()->create([
        'project_id' => $project->id,
        'project_stage_id' => $stage->id,
        'creator_id' => $pm->id,
        'assignee_id' => $pm->id,
        'title' => 'Kickoff prep',
        'priority' => TaskPriorityEnum::High,
        'status' => TaskStatusEnum::Todo,
    ]);
    $childTask = Task::query()->create([
        'project_id' => $project->id,
        'project_stage_id' => $stage->id,
        'parent_task_id' => $parentTask->id,
        'creator_id' => $pm->id,
        'assignee_id' => $am->id,
        'title' => 'Share deck',
        'priority' => TaskPriorityEnum::Medium,
        'status' => TaskStatusEnum::InProgress,
    ]);
    $parentTask->watchers()->attach($am->id);

    expect($parentTask->subtasks()->pluck('id')->all())->toBe([$childTask->id])
        ->and($childTask->parent?->id)->toBe($parentTask->id)
        ->and($parentTask->watchers()->pluck('users.id')->all())->toBe([$am->id]);
});

it('seeds Standard Client Onboarding template idempotently via ProjectDefaultsSeeder and DatabaseSeeder', function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->seed(ProjectDefaultsSeeder::class);
    $this->seed(DatabaseSeeder::class);

    expect(ProjectTemplate::query()->where('name', 'Standard Client Onboarding')->count())->toBe(1);

    $template = ProjectTemplate::query()->where('name', 'Standard Client Onboarding')->firstOrFail();
    expect($template->is_active)->toBeTrue()
        ->and(collect($template->default_stages)->pluck('key')->all())->toBe([
            'backlog',
            'todo',
            'in_progress',
            'review',
            'done',
        ])
        ->and(collect($template->default_stages)->pluck('category')->all())->toBe([
            ProjectStageCategoryEnum::Backlog->value,
            ProjectStageCategoryEnum::Todo->value,
            ProjectStageCategoryEnum::InProgress->value,
            ProjectStageCategoryEnum::Review->value,
            ProjectStageCategoryEnum::Done->value,
        ]);

    $tasks = $template->tasks()->get();
    expect($tasks)->toHaveCount(6)
        ->and($tasks->whereNotNull('parent_template_task_id')->count())->toBeGreaterThanOrEqual(1)
        ->and($tasks->pluck('assignee_role_hint')->filter()->map->value->unique()->all())
        ->toContain(TemplateAssigneeRoleHintEnum::Pm->value, TemplateAssigneeRoleHintEnum::Am->value)
        ->and($tasks->whereNotNull('relative_due_days')->count())->toBe(6);
});

it('enforces ProjectPolicy, Project silo visibility with account_manager_id and project collaborators, and Superadmin/Admin parity', function (): void {
    $dept = Department::query()->create(['name' => 'Delivery', 'slug' => 'projects-silo-delivery']);

    $superadmin = createProjectsTestUser($dept, 'EMP-PRJ-SA', 'sa.prj@acme.internal', RoleEnum::Superadmin);
    $admin = createProjectsTestUser($dept, 'EMP-PRJ-ADM', 'adm.prj@acme.internal', RoleEnum::Admin);
    $directorA = createProjectsTestUser($dept, 'EMP-PRJ-DIRA', 'dir.a.prj@acme.internal', RoleEnum::Director);
    $pmA = createProjectsTestUser($dept, 'EMP-PRJ-PMA', 'pm.a.prj@acme.internal', RoleEnum::ProjectManager, $directorA);
    $pmB = createProjectsTestUser($dept, 'EMP-PRJ-PMB', 'pm.b.prj@acme.internal', RoleEnum::ProjectManager);
    $amUser = createProjectsTestUser($dept, 'EMP-PRJ-AM', 'am.prj@acme.internal', RoleEnum::AccountManager);
    $supportUser = createProjectsTestUser($dept, 'EMP-PRJ-SUP', 'sup.prj@acme.internal', RoleEnum::Support);
    $itUser = createProjectsTestUser($dept, 'EMP-PRJ-IT', 'it.prj@acme.internal', RoleEnum::It);

    $project = Project::query()->create([
        'code' => 'PRJ-2026-0010',
        'owner_id' => $pmA->id,
        'account_manager_id' => $amUser->id,
        'department_id' => $dept->id,
        'name' => 'Silo A Implementation',
    ]);

    // VisibleTo scope check
    expect(Project::query()->visibleTo($pmA)->pluck('id')->all())->toBe([$project->id])
        ->and(Project::query()->visibleTo($directorA)->pluck('id')->all())->toBe([$project->id])
        ->and(Project::query()->visibleTo($amUser)->pluck('id')->all())->toBe([$project->id])
        ->and(Project::query()->visibleTo($admin)->pluck('id')->all())->toBe([$project->id])
        ->and(Project::query()->visibleTo($superadmin)->pluck('id')->all())->toBe([$project->id])
        ->and(Project::query()->visibleTo($pmB)->count())->toBe(0)
        ->and(Project::query()->visibleTo($supportUser)->count())->toBe(0);

    // Owner (PM A) & Silo Director (Director A): full Project CRUD + stages + collaborators
    foreach ([$pmA, $directorA, $admin, $superadmin] as $authorized) {
        expect(Gate::forUser($authorized)->allows('view', $project))->toBeTrue()
            ->and(Gate::forUser($authorized)->allows('update', $project))->toBeTrue()
            ->and(Gate::forUser($authorized)->allows('delete', $project))->toBeTrue()
            ->and(Gate::forUser($authorized)->allows('manageStages', $project))->toBeTrue()
            ->and(Gate::forUser($authorized)->allows('manageCollaborators', $project))->toBeTrue();
    }

    // Account Manager: silo view via account_manager_id, lacks projects.update/delete
    expect(Gate::forUser($amUser)->allows('viewAny', Project::class))->toBeTrue()
        ->and(Gate::forUser($amUser)->allows('create', Project::class))->toBeFalse()
        ->and(Gate::forUser($amUser)->allows('view', $project))->toBeTrue()
        ->and(Gate::forUser($amUser)->allows('update', $project))->toBeFalse()
        ->and(Gate::forUser($amUser)->allows('manageStages', $project))->toBeFalse()
        ->and(Gate::forUser($amUser)->allows('delete', $project))->toBeFalse()
        ->and(Gate::forUser($amUser)->allows('manageCollaborators', $project))->toBeFalse();

    // Cross-silo PM B without collaborator grant: all denied
    expect(Gate::forUser($pmB)->allows('view', $project))->toBeFalse()
        ->and(Gate::forUser($pmB)->allows('update', $project))->toBeFalse()
        ->and(Gate::forUser($pmB)->allows('delete', $project))->toBeFalse()
        ->and(Gate::forUser($pmB)->allows('manageStages', $project))->toBeFalse()
        ->and(Gate::forUser($pmB)->allows('manageCollaborators', $project))->toBeFalse();

    // Grant PM B Viewer -> Editor -> Manager collaborator on Project (collaboratable_type = 'project')
    app(GrantEntityCollaboratorAction::class)->execute(
        collaboratable: $project,
        user: $pmB,
        accessLevel: CollaboratorAccessLevel::Viewer,
        roleLabel: CollaboratorRoleLabel::Observer,
        grantedBy: $pmA,
    );
    expect(Project::query()->visibleTo($pmB)->pluck('id')->all())->toBe([$project->id])
        ->and(Gate::forUser($pmB)->allows('view', $project))->toBeTrue()
        ->and(Gate::forUser($pmB)->allows('update', $project))->toBeFalse()
        ->and(Gate::forUser($pmB)->allows('manageStages', $project))->toBeFalse();

    app(GrantEntityCollaboratorAction::class)->execute(
        collaboratable: $project,
        user: $pmB,
        accessLevel: CollaboratorAccessLevel::Editor,
        roleLabel: CollaboratorRoleLabel::ProjectManager,
        grantedBy: $pmA,
    );
    expect(Gate::forUser($pmB)->allows('view', $project))->toBeTrue()
        ->and(Gate::forUser($pmB)->allows('update', $project))->toBeTrue()
        ->and(Gate::forUser($pmB)->allows('manageStages', $project))->toBeTrue()
        ->and(Gate::forUser($pmB)->allows('delete', $project))->toBeFalse()
        ->and(Gate::forUser($pmB)->allows('manageCollaborators', $project))->toBeFalse();

    app(GrantEntityCollaboratorAction::class)->execute(
        collaboratable: $project,
        user: $pmB,
        accessLevel: CollaboratorAccessLevel::Manager,
        roleLabel: CollaboratorRoleLabel::ProjectManager,
        grantedBy: $pmA,
    );
    expect(Gate::forUser($pmB)->allows('delete', $project))->toBeTrue()
        ->and(Gate::forUser($pmB)->allows('manageCollaborators', $project))->toBeTrue();

    // IT has projects.update (can edit when granted Editor), lacks projects.delete
    app(GrantEntityCollaboratorAction::class)->execute(
        collaboratable: $project,
        user: $itUser,
        accessLevel: CollaboratorAccessLevel::Manager,
        roleLabel: CollaboratorRoleLabel::ItLead,
        grantedBy: $pmA,
    );
    expect(Gate::forUser($itUser)->allows('view', $project))->toBeTrue()
        ->and(Gate::forUser($itUser)->allows('update', $project))->toBeTrue()
        ->and(Gate::forUser($itUser)->allows('manageStages', $project))->toBeTrue()
        ->and(Gate::forUser($itUser)->allows('manageCollaborators', $project))->toBeTrue()
        ->and(Gate::forUser($itUser)->allows('delete', $project))->toBeFalse();

    // Support with Manager collaborator cannot update/delete because role lacks projects.update/delete
    app(GrantEntityCollaboratorAction::class)->execute(
        collaboratable: $project,
        user: $supportUser,
        accessLevel: CollaboratorAccessLevel::Manager,
        roleLabel: CollaboratorRoleLabel::SupportLead,
        grantedBy: $pmA,
    );
    expect(Gate::forUser($supportUser)->allows('view', $project))->toBeTrue()
        ->and(Gate::forUser($supportUser)->allows('update', $project))->toBeFalse()
        ->and(Gate::forUser($supportUser)->allows('delete', $project))->toBeFalse();

    // Unpersisted and soft-deleted projects fail closed
    expect(Gate::forUser($admin)->allows('view', new Project))->toBeFalse();
    $project->delete();
    expect(Gate::forUser($admin)->allows('view', $project))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('update', $project))->toBeFalse()
        ->and(Gate::forUser($superadmin)->allows('delete', $project))->toBeFalse();
});

it('enforces TaskPolicy for project-bound tasks and standalone CRM tasks', function (): void {
    $this->seed(CrmDefaultsSeeder::class);
    $dept = Department::query()->create(['name' => 'Operations', 'slug' => 'projects-task-ops']);

    $superadmin = createProjectsTestUser($dept, 'EMP-TSK-SA', 'sa.tsk@acme.internal', RoleEnum::Superadmin);
    $admin = createProjectsTestUser($dept, 'EMP-TSK-ADM', 'adm.tsk@acme.internal', RoleEnum::Admin);
    $directorA = createProjectsTestUser($dept, 'EMP-TSK-DIRA', 'dir.a.tsk@acme.internal', RoleEnum::Director);
    $directorB = createProjectsTestUser($dept, 'EMP-TSK-DIRB', 'dir.b.tsk@acme.internal', RoleEnum::Director);
    $repA = createProjectsTestUser($dept, 'EMP-TSK-REPA', 'rep.a.tsk@acme.internal', RoleEnum::Sales, $directorA);
    $repB = createProjectsTestUser($dept, 'EMP-TSK-REPB', 'rep.b.tsk@acme.internal', RoleEnum::Sales, $directorB);
    $pmA = createProjectsTestUser($dept, 'EMP-TSK-PMA', 'pm.a.tsk@acme.internal', RoleEnum::ProjectManager, $directorA);
    $pmB = createProjectsTestUser($dept, 'EMP-TSK-PMB', 'pm.b.tsk@acme.internal', RoleEnum::ProjectManager);
    $supportUser = createProjectsTestUser($dept, 'EMP-TSK-SUP', 'sup.tsk@acme.internal', RoleEnum::Support);
    $watcherUser = createProjectsTestUser($dept, 'EMP-TSK-WAT', 'wat.tsk@acme.internal', RoleEnum::ProjectManager);

    $pipeline = Pipeline::query()->where('slug', 'new-business')->firstOrFail();
    $dealStage = $pipeline->stages()->orderBy('sort_order')->firstOrFail();
    $company = Company::query()->create(['name' => 'Task Client', 'owner_id' => $repA->id]);
    $deal = Deal::query()->create([
        'company_id' => $company->id,
        'pipeline_id' => $pipeline->id,
        'stage_id' => $dealStage->id,
        'owner_id' => $repA->id,
        'title' => 'Task Linked Deal',
        'stage_entered_at' => now(),
    ]);

    $project = Project::query()->create([
        'code' => 'PRJ-2026-0020',
        'owner_id' => $pmA->id,
        'department_id' => $dept->id,
        'name' => 'Project Tasks Scope',
    ]);
    $stage = ProjectStage::query()->create([
        'project_id' => $project->id,
        'name' => 'To Do',
        'category' => ProjectStageCategoryEnum::Todo,
        'sort_order' => 1,
    ]);

    // TaskPolicy::create with optional target context
    expect(Gate::forUser($pmA)->allows('create', Task::class))->toBeTrue()
        ->and(Gate::forUser($pmA)->allows('create', [Task::class, $project]))->toBeTrue()
        ->and(Gate::forUser($pmB)->allows('create', [Task::class, $project]))->toBeFalse()
        ->and(Gate::forUser($repA)->allows('create', Task::class))->toBeFalse()
        ->and(Gate::forUser($directorA)->allows('create', [Task::class, $deal]))->toBeTrue()
        ->and(Gate::forUser($pmB)->allows('create', [Task::class, $deal]))->toBeFalse();

    // Project-bound task
    app(GrantEntityCollaboratorAction::class)->execute(
        collaboratable: $project,
        user: $supportUser,
        accessLevel: CollaboratorAccessLevel::Viewer,
        roleLabel: CollaboratorRoleLabel::SupportLead,
        grantedBy: $pmA,
    );

    $projectTask = Task::query()->create([
        'project_id' => $project->id,
        'project_stage_id' => $stage->id,
        'linkable_type' => 'deal',
        'linkable_id' => $deal->id,
        'creator_id' => $pmA->id,
        'assignee_id' => $supportUser->id,
        'title' => 'Configure SSO',
    ]);

    expect(Gate::forUser($pmA)->allows('view', $projectTask))->toBeTrue()
        ->and(Gate::forUser($pmA)->allows('update', $projectTask))->toBeTrue()
        ->and(Gate::forUser($pmA)->allows('move', $projectTask))->toBeTrue()
        ->and(Gate::forUser($pmA)->allows('delete', $projectTask))->toBeTrue()
        ->and(Gate::forUser($directorA)->allows('view', $projectTask))->toBeTrue()
        ->and(Gate::forUser($directorA)->allows('update', $projectTask))->toBeTrue()
        ->and(Gate::forUser($supportUser)->allows('view', $projectTask))->toBeTrue()
        ->and(Gate::forUser($supportUser)->allows('update', $projectTask))->toBeTrue()
        ->and(Gate::forUser($supportUser)->allows('move', $projectTask))->toBeTrue()
        ->and(Gate::forUser($supportUser)->allows('toggleWatcher', $projectTask))->toBeTrue()
        ->and(Gate::forUser($repA)->allows('view', $projectTask))->toBeFalse() // Project-bound task does not leak via linkable Deal
        ->and(Gate::forUser($pmB)->allows('view', $projectTask))->toBeFalse()
        ->and(Gate::forUser($pmB)->allows('update', $projectTask))->toBeFalse()
        ->and(Gate::forUser($pmB)->allows('move', $projectTask))->toBeFalse()
        ->and(Gate::forUser($pmB)->allows('delete', $projectTask))->toBeFalse()
        ->and(Gate::forUser($pmB)->allows('toggleWatcher', $projectTask))->toBeFalse();

    // Revoking Support's collaborator access on Project revokes view AND update/move/delete even though Support is assignee
    app(RevokeEntityCollaboratorAction::class)->execute($project, $supportUser);
    expect(Gate::forUser($supportUser)->allows('view', $projectTask))->toBeFalse()
        ->and(Gate::forUser($supportUser)->allows('update', $projectTask))->toBeFalse()
        ->and(Gate::forUser($supportUser)->allows('move', $projectTask))->toBeFalse()
        ->and(Gate::forUser($supportUser)->allows('delete', $projectTask))->toBeFalse();

    // Standalone CRM task linked to Deal
    $standaloneTask = Task::query()->create([
        'project_id' => null,
        'linkable_type' => 'deal',
        'linkable_id' => $deal->id,
        'creator_id' => $directorA->id,
        'assignee_id' => $pmA->id,
        'title' => 'Follow up with legal',
    ]);
    $standaloneTask->watchers()->attach($watcherUser->id);

    // Director B granted Editor collaborator on Deal can view/update/move/delete standalone task via linkable
    app(GrantEntityCollaboratorAction::class)->execute(
        collaboratable: $deal,
        user: $directorB,
        accessLevel: CollaboratorAccessLevel::Editor,
        roleLabel: CollaboratorRoleLabel::SalesOverlay,
        grantedBy: $directorA,
    );

    // Creator (Director A), Assignee (PM A), Linkable viewer (Rep A), Linkable editor (Director B), Watcher (watcherUser), Admin, Superadmin
    expect(Gate::forUser($directorA)->allows('view', $standaloneTask))->toBeTrue()
        ->and(Gate::forUser($directorA)->allows('update', $standaloneTask))->toBeTrue()
        ->and(Gate::forUser($directorB)->allows('view', $standaloneTask))->toBeTrue()
        ->and(Gate::forUser($directorB)->allows('update', $standaloneTask))->toBeTrue()
        ->and(Gate::forUser($directorB)->allows('move', $standaloneTask))->toBeTrue()
        ->and(Gate::forUser($directorB)->allows('delete', $standaloneTask))->toBeTrue()
        ->and(Gate::forUser($pmA)->allows('view', $standaloneTask))->toBeTrue()
        ->and(Gate::forUser($pmA)->allows('update', $standaloneTask))->toBeTrue()
        ->and(Gate::forUser($pmA)->allows('delete', $standaloneTask))->toBeTrue()
        ->and(Gate::forUser($repA)->allows('view', $standaloneTask))->toBeTrue()
        ->and(Gate::forUser($repA)->allows('toggleWatcher', $standaloneTask))->toBeTrue()
        ->and(Gate::forUser($repA)->allows('update', $standaloneTask))->toBeFalse() // Sales lacks tasks.update
        ->and(Gate::forUser($watcherUser)->allows('view', $standaloneTask))->toBeTrue()
        ->and(Gate::forUser($watcherUser)->allows('toggleWatcher', $standaloneTask))->toBeTrue()
        ->and(Gate::forUser($watcherUser)->allows('update', $standaloneTask))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('view', $standaloneTask))->toBeTrue()
        ->and(Gate::forUser($superadmin)->allows('update', $standaloneTask))->toBeTrue()
        ->and(Gate::forUser($repB)->allows('view', $standaloneTask))->toBeFalse()
        ->and(Gate::forUser($repB)->allows('update', $standaloneTask))->toBeFalse()
        ->and(Gate::forUser($pmB)->allows('view', $standaloneTask))->toBeFalse()
        ->and(Gate::forUser($pmB)->allows('update', $standaloneTask))->toBeFalse();

    // Personal task without project_id or linkable is private to creator/assignee/watcher/admins
    $personalTask = Task::query()->create([
        'project_id' => null,
        'creator_id' => $pmA->id,
        'assignee_id' => null,
        'title' => 'Personal checklist',
    ]);
    expect(Gate::forUser($pmA)->allows('view', $personalTask))->toBeTrue()
        ->and(Gate::forUser($directorA)->allows('view', $personalTask))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('view', $personalTask))->toBeTrue()
        ->and(Gate::forUser($superadmin)->allows('view', $personalTask))->toBeTrue();

    // Soft-deleting linked Deal removes linkable access while keeping creator/assignee/watcher access
    $deal->delete();
    expect(Gate::forUser($repA)->allows('view', $standaloneTask))->toBeFalse()
        ->and(Gate::forUser($directorB)->allows('view', $standaloneTask))->toBeFalse()
        ->and(Gate::forUser($directorB)->allows('update', $standaloneTask))->toBeFalse()
        ->and(Gate::forUser($pmA)->allows('view', $standaloneTask))->toBeTrue()
        ->and(Gate::forUser($watcherUser)->allows('view', $standaloneTask))->toBeTrue();

    // Soft-deleting parent Project denies non-admin on projectTask while unrestricted admin retains access
    $project->delete();
    expect(Gate::forUser($pmA)->allows('view', $projectTask->fresh()))->toBeFalse()
        ->and(Gate::forUser($pmA)->allows('update', $projectTask->fresh()))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('view', $projectTask->fresh()))->toBeTrue()
        ->and(Gate::forUser($superadmin)->allows('delete', $projectTask->fresh()))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('create', [Task::class, $project]))->toBeFalse();

    // Unpersisted and trashed tasks fail closed even for Superadmin/Admin
    expect(Gate::forUser($superadmin)->allows('view', new Task))->toBeFalse();
    $standaloneTask->delete();
    expect(Gate::forUser($superadmin)->allows('view', $standaloneTask))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('update', $standaloneTask))->toBeFalse()
        ->and(Gate::forUser($directorA)->allows('delete', $standaloneTask))->toBeFalse();
});

it('enforces DealHandoffPolicy and ProjectTemplatePolicy across all workflow actions and roles', function (): void {
    $this->seed(CrmDefaultsSeeder::class);
    $dept = Department::query()->create(['name' => 'Commercial', 'slug' => 'projects-handoff-comm']);

    $superadmin = createProjectsTestUser($dept, 'EMP-HO-SA', 'sa.ho@acme.internal', RoleEnum::Superadmin);
    $admin = createProjectsTestUser($dept, 'EMP-HO-ADM', 'adm.ho@acme.internal', RoleEnum::Admin);
    $directorA = createProjectsTestUser($dept, 'EMP-HO-DIRA', 'dir.a.ho@acme.internal', RoleEnum::Director);
    $repA = createProjectsTestUser($dept, 'EMP-HO-REPA', 'rep.a.ho@acme.internal', RoleEnum::Sales, $directorA);
    $repB = createProjectsTestUser($dept, 'EMP-HO-REPB', 'rep.b.ho@acme.internal', RoleEnum::Sales);
    $opsMgr = createProjectsTestUser($dept, 'EMP-HO-OPS', 'ops.ho@acme.internal', RoleEnum::OperationsManager);
    $pm = createProjectsTestUser($dept, 'EMP-HO-PM', 'pm.ho@acme.internal', RoleEnum::ProjectManager);
    $pmOther = createProjectsTestUser($dept, 'EMP-HO-PM2', 'pm2.ho@acme.internal', RoleEnum::ProjectManager);
    $am = createProjectsTestUser($dept, 'EMP-HO-AM', 'am.ho@acme.internal', RoleEnum::AccountManager);
    $support = createProjectsTestUser($dept, 'EMP-HO-SUP', 'sup.ho@acme.internal', RoleEnum::Support);

    $template = ProjectTemplate::query()->create([
        'name' => 'Policy Template',
        'default_stages' => [['key' => 'todo', 'name' => 'To Do', 'category' => 'todo', 'sort_order' => 1, 'wip_limit' => null]],
        'created_by_id' => $opsMgr->id,
    ]);

    // ProjectTemplatePolicy
    foreach ([$superadmin, $admin, $opsMgr] as $mgr) {
        expect(Gate::forUser($mgr)->allows('viewAny', ProjectTemplate::class))->toBeTrue()
            ->and(Gate::forUser($mgr)->allows('view', $template))->toBeTrue()
            ->and(Gate::forUser($mgr)->allows('create', ProjectTemplate::class))->toBeTrue()
            ->and(Gate::forUser($mgr)->allows('update', $template))->toBeTrue()
            ->and(Gate::forUser($mgr)->allows('delete', $template))->toBeTrue();
    }
    expect(Gate::forUser($directorA)->allows('viewAny', ProjectTemplate::class))->toBeTrue()
        ->and(Gate::forUser($directorA)->allows('view', $template))->toBeTrue()
        ->and(Gate::forUser($directorA)->allows('create', ProjectTemplate::class))->toBeFalse()
        ->and(Gate::forUser($pm)->allows('viewAny', ProjectTemplate::class))->toBeFalse()
        ->and(Gate::forUser($repA)->allows('view', $template))->toBeFalse();

    $pipeline = Pipeline::query()->where('slug', 'new-business')->firstOrFail();
    $stage = $pipeline->stages()->orderBy('sort_order')->firstOrFail();
    $company = Company::query()->create(['name' => 'Handoff Silo Co', 'owner_id' => $repA->id]);
    $deal = Deal::query()->create([
        'company_id' => $company->id,
        'pipeline_id' => $pipeline->id,
        'stage_id' => $stage->id,
        'owner_id' => $repA->id,
        'title' => 'Handoff Deal',
        'stage_entered_at' => now(),
    ]);

    $handoff = DealHandoff::query()->create([
        'deal_id' => $deal->id,
        'company_id' => $company->id,
        'submitted_by_id' => $repA->id,
        'assigned_pm_id' => $pm->id,
        'assigned_am_id' => $am->id,
        'project_template_id' => $template->id,
        'status' => DealHandoffStatusEnum::PendingReview,
        'scope_notes_json' => ['type' => 'doc', 'content' => []],
        'submitted_at' => now(),
    ]);

    // Submit
    expect(Gate::forUser($repA)->allows('submit', [DealHandoff::class, $deal]))->toBeTrue()
        ->and(Gate::forUser($directorA)->allows('submit', [DealHandoff::class, $deal]))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('submit', [DealHandoff::class, $deal]))->toBeTrue()
        ->and(Gate::forUser($superadmin)->allows('submit', [DealHandoff::class, $deal]))->toBeTrue()
        ->and(Gate::forUser($repB)->allows('submit', [DealHandoff::class, $deal]))->toBeFalse()
        ->and(Gate::forUser($opsMgr)->allows('submit', [DealHandoff::class, $deal]))->toBeFalse()
        ->and(Gate::forUser($pm)->allows('submit', [DealHandoff::class, $deal]))->toBeFalse()
        ->and(Gate::forUser($am)->allows('submit', [DealHandoff::class, $deal]))->toBeFalse();

    // View
    foreach ([$superadmin, $admin, $opsMgr, $directorA, $repA, $pm, $am] as $viewer) {
        expect(Gate::forUser($viewer)->allows('view', $handoff))->toBeTrue();
    }
    foreach ([$repB, $pmOther, $support] as $deniedViewer) {
        expect(Gate::forUser($deniedViewer)->allows('view', $handoff))->toBeFalse();
    }

    // Resubmit
    expect(Gate::forUser($repA)->allows('resubmit', $handoff))->toBeTrue()
        ->and(Gate::forUser($directorA)->allows('resubmit', $handoff))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('resubmit', $handoff))->toBeTrue()
        ->and(Gate::forUser($superadmin)->allows('resubmit', $handoff))->toBeTrue()
        ->and(Gate::forUser($repB)->allows('resubmit', $handoff))->toBeFalse()
        ->and(Gate::forUser($opsMgr)->allows('resubmit', $handoff))->toBeFalse()
        ->and(Gate::forUser($pm)->allows('resubmit', $handoff))->toBeFalse();

    // Request Changes
    foreach ([$superadmin, $admin, $opsMgr, $directorA, $pm] as $requester) {
        expect(Gate::forUser($requester)->allows('requestChanges', $handoff))->toBeTrue();
    }
    foreach ([$repA, $am, $support] as $deniedRequester) {
        expect(Gate::forUser($deniedRequester)->allows('requestChanges', $handoff))->toBeFalse();
    }

    // Reject & Approve
    foreach ([$superadmin, $admin, $opsMgr, $directorA] as $approver) {
        expect(Gate::forUser($approver)->allows('reject', $handoff))->toBeTrue()
            ->and(Gate::forUser($approver)->allows('approve', $handoff))->toBeTrue();
    }
    foreach ([$pm, $repA, $am, $support] as $nonApprover) {
        expect(Gate::forUser($nonApprover)->allows('reject', $handoff))->toBeFalse()
            ->and(Gate::forUser($nonApprover)->allows('approve', $handoff))->toBeFalse();
    }

    // Unpersisted handoff/template and soft-deleted deal fail closed
    expect(Gate::forUser($superadmin)->allows('view', new DealHandoff))->toBeFalse()
        ->and(Gate::forUser($superadmin)->allows('approve', new DealHandoff))->toBeFalse()
        ->and(Gate::forUser($superadmin)->allows('view', new ProjectTemplate))->toBeFalse();

    // Submitter keeps resubmit right even if deal owner changed, unless deal is trashed or role changes
    $deal->update(['owner_id' => $repB->id]);
    expect(Gate::forUser($repA)->allows('resubmit', $handoff->fresh()))->toBeTrue();

    $deal->delete();
    expect(Gate::forUser($superadmin)->allows('submit', [DealHandoff::class, $deal]))->toBeFalse()
        ->and(Gate::forUser($superadmin)->allows('resubmit', $handoff->fresh()))->toBeFalse()
        ->and(Gate::forUser($repA)->allows('resubmit', $handoff->fresh()))->toBeFalse();
});

it('fails closed when viewer is suspended, offboarded, or soft-deleted across all Projects models and policies', function (): void {
    $this->seed(CrmDefaultsSeeder::class);
    $dept = Department::query()->create(['name' => 'Security', 'slug' => 'projects-inactive-sec']);

    $admin = createProjectsTestUser($dept, 'EMP-SEC-ADM', 'adm.sec@acme.internal', RoleEnum::Admin);
    $superadmin = createProjectsTestUser($dept, 'EMP-SEC-SA', 'sa.sec@acme.internal', RoleEnum::Superadmin);
    $pm = createProjectsTestUser($dept, 'EMP-SEC-PM', 'pm.sec@acme.internal', RoleEnum::ProjectManager);
    $am = createProjectsTestUser($dept, 'EMP-SEC-AM', 'am.sec@acme.internal', RoleEnum::AccountManager);
    $opsMgr = createProjectsTestUser($dept, 'EMP-SEC-OPS', 'ops.sec@acme.internal', RoleEnum::OperationsManager);
    $collab = createProjectsTestUser($dept, 'EMP-SEC-COL', 'col.sec@acme.internal', RoleEnum::ProjectManager);
    $watcher = createProjectsTestUser($dept, 'EMP-SEC-WAT', 'wat.sec@acme.internal', RoleEnum::ProjectManager);

    $template = ProjectTemplate::query()->create([
        'name' => 'Inactive Guard Template',
        'default_stages' => [['key' => 'todo', 'name' => 'To Do', 'category' => 'todo', 'sort_order' => 1, 'wip_limit' => null]],
        'created_by_id' => $opsMgr->id,
    ]);

    $pipeline = Pipeline::query()->where('slug', 'new-business')->firstOrFail();
    $dealStage = $pipeline->stages()->orderBy('sort_order')->firstOrFail();
    $company = Company::query()->create(['name' => 'Inactive Test Co', 'owner_id' => $opsMgr->id]);
    $deal = Deal::query()->create([
        'company_id' => $company->id,
        'pipeline_id' => $pipeline->id,
        'stage_id' => $dealStage->id,
        'owner_id' => $opsMgr->id,
        'title' => 'Inactive Deal',
        'stage_entered_at' => now(),
    ]);

    $handoff = DealHandoff::query()->create([
        'deal_id' => $deal->id,
        'company_id' => $company->id,
        'submitted_by_id' => $opsMgr->id,
        'assigned_pm_id' => $pm->id,
        'assigned_am_id' => $am->id,
        'project_template_id' => $template->id,
        'status' => DealHandoffStatusEnum::PendingReview,
        'scope_notes_json' => ['type' => 'doc', 'content' => []],
        'submitted_at' => now(),
    ]);

    $project = Project::query()->create([
        'code' => 'PRJ-2026-0099',
        'company_id' => $company->id,
        'primary_deal_id' => $deal->id,
        'owner_id' => $pm->id,
        'account_manager_id' => $am->id,
        'department_id' => $dept->id,
        'name' => 'Inactive Guard Project',
    ]);

    app(GrantEntityCollaboratorAction::class)->execute(
        collaboratable: $project,
        user: $collab,
        accessLevel: CollaboratorAccessLevel::Manager,
        roleLabel: CollaboratorRoleLabel::ProjectManager,
        grantedBy: $pm,
    );

    $task = Task::query()->create([
        'project_id' => null,
        'creator_id' => $pm->id,
        'assignee_id' => $collab->id,
        'title' => 'Inactive Guard Task',
    ]);
    $task->watchers()->attach($watcher->id);

    // Suspend, offboard, and soft-delete users
    $admin->update(['status' => UserStatusEnum::Suspended]);
    $superadmin->update(['status' => UserStatusEnum::Offboarded]);
    $pm->update(['status' => UserStatusEnum::Suspended]);
    $am->update(['status' => UserStatusEnum::Offboarded]);
    $opsMgr->delete();
    $collab->update(['status' => UserStatusEnum::Suspended]);
    $watcher->delete();

    foreach ([$admin, $superadmin, $pm, $am, $opsMgr, $collab, $watcher] as $inactive) {
        expect(Project::query()->visibleTo($inactive)->count())->toBe(0)
            ->and(Gate::forUser($inactive)->allows('view', $project))->toBeFalse()
            ->and(Gate::forUser($inactive)->allows('update', $project))->toBeFalse()
            ->and(Gate::forUser($inactive)->allows('view', $task))->toBeFalse()
            ->and(Gate::forUser($inactive)->allows('update', $task))->toBeFalse()
            ->and(Gate::forUser($inactive)->allows('toggleWatcher', $task))->toBeFalse()
            ->and(Gate::forUser($inactive)->allows('view', $handoff))->toBeFalse()
            ->and(Gate::forUser($inactive)->allows('approve', $handoff))->toBeFalse()
            ->and(Gate::forUser($inactive)->allows('view', $template))->toBeFalse();
    }
});
