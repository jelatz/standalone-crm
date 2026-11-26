<?php

use App\Modules\Core\Rbac\Actions\GrantEntityCollaboratorAction;
use App\Modules\Core\Rbac\Enums\CollaboratorAccessLevel;
use App\Modules\Core\Rbac\Enums\CollaboratorRoleLabel;
use App\Modules\Core\Rbac\Enums\RoleEnum;
use App\Modules\Core\Users\Actions\RebuildUserHierarchyClosureAction;
use App\Modules\Core\Users\Enums\UserStatusEnum;
use App\Modules\Core\Users\Models\Department;
use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Companies\Models\Company;
use App\Modules\Crm\Contacts\Models\Contact;
use App\Modules\Crm\Deals\Models\Deal;
use App\Modules\Crm\Pipelines\Models\Pipeline;
use App\Modules\Crm\Timeline\Actions\QueryEntityTimelineAction;
use App\Modules\Crm\Timeline\Actions\RecordActivityAction;
use App\Modules\Crm\Timeline\Enums\ActivityTypeEnum;
use App\Modules\Crm\Timeline\Models\Activity;
use App\Modules\Crm\Timeline\Models\ActivityLink;
use App\Modules\Projects\Handoffs\Enums\DealHandoffStatusEnum;
use App\Modules\Projects\Handoffs\Models\DealHandoff;
use App\Modules\Projects\Projects\Enums\ProjectHealthEnum;
use App\Modules\Projects\Projects\Enums\ProjectStageCategoryEnum;
use App\Modules\Projects\Projects\Enums\ProjectStatusEnum;
use App\Modules\Projects\Projects\Models\Project;
use App\Modules\Projects\Projects\Models\ProjectStage;
use App\Modules\Projects\Tasks\Actions\ListMyWorkAction;
use App\Modules\Projects\Tasks\Enums\TaskPriorityEnum;
use App\Modules\Projects\Tasks\Enums\TaskStatusEnum;
use App\Modules\Projects\Tasks\Models\Task;
use Carbon\CarbonImmutable;
use Database\Seeders\CrmDefaultsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

function createProjectsFeatureUser(
    Department $department,
    string $code,
    string $email,
    ?RoleEnum $role = null,
    ?User $manager = null,
    UserStatusEnum $status = UserStatusEnum::Active,
): User {
    $user = User::query()->create([
        'employee_code' => $code,
        'name' => $code,
        'email' => $email,
        'department_id' => $department->id,
        'manager_id' => $manager?->id,
        'status' => $status,
    ]);

    if ($role !== null) {
        $user->assignRole($role->value);
    }

    app(RebuildUserHierarchyClosureAction::class)->execute($user);

    return $user;
}

beforeEach(function (): void {
    Cache::flush();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(CrmDefaultsSeeder::class);
});

afterEach(function (): void {
    Carbon::setTestNow();
    Cache::flush();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('enforces manual Project CRUD, filters without company oracle, stage management, collaborators, and IDOR boundaries', function (): void {
    $salesDept = Department::query()->create(['name' => 'Sales', 'slug' => 'pt-sales']);
    $deliveryDept = Department::query()->create(['name' => 'Delivery', 'slug' => 'pt-delivery']);

    $superadmin = createProjectsFeatureUser($deliveryDept, 'EMP-PT-SA', 'sa.pt@acme.internal', RoleEnum::Superadmin);
    $admin = createProjectsFeatureUser($deliveryDept, 'EMP-PT-ADM', 'adm.pt@acme.internal', RoleEnum::Admin);
    $directorA = createProjectsFeatureUser($deliveryDept, 'EMP-PT-DIRA', 'dir.a.pt@acme.internal', RoleEnum::Director);
    $pmA = createProjectsFeatureUser($deliveryDept, 'EMP-PT-PMA', 'pm.a.pt@acme.internal', RoleEnum::ProjectManager, $directorA);
    $pmB = createProjectsFeatureUser($deliveryDept, 'EMP-PT-PMB', 'pm.b.pt@acme.internal', RoleEnum::ProjectManager);
    $repA = createProjectsFeatureUser($salesDept, 'EMP-PT-REPA', 'rep.a.pt@acme.internal', RoleEnum::Sales);
    $amUser = createProjectsFeatureUser($salesDept, 'EMP-PT-AM', 'am.pt@acme.internal', RoleEnum::AccountManager);
    $supportUser = createProjectsFeatureUser($deliveryDept, 'EMP-PT-SUP', 'sup.pt@acme.internal', RoleEnum::Support);
    $suspendedUser = createProjectsFeatureUser($deliveryDept, 'EMP-PT-SUSP', 'susp.pt@acme.internal', RoleEnum::ProjectManager, null, UserStatusEnum::Suspended);
    $zeroRole = createProjectsFeatureUser($deliveryDept, 'EMP-PT-ZERO', 'zero.pt@acme.internal', null);

    $pipeline = Pipeline::query()->where('slug', 'new-business')->firstOrFail();
    $dealStage = $pipeline->stages()->orderBy('sort_order')->firstOrFail();

    $companyA = Company::query()->create(['name' => 'Alpha Manufacturing', 'owner_id' => $pmA->id]);
    $dealA = Deal::query()->create([
        'company_id' => $companyA->id,
        'pipeline_id' => $pipeline->id,
        'stage_id' => $dealStage->id,
        'owner_id' => $pmA->id,
        'title' => 'Alpha Deal',
        'stage_entered_at' => now(),
    ]);
    $companyB = Company::query()->create(['name' => 'Secret Sales Co', 'owner_id' => $repA->id]);
    $dealB = Deal::query()->create([
        'company_id' => $companyB->id,
        'pipeline_id' => $pipeline->id,
        'stage_id' => $dealStage->id,
        'owner_id' => $repA->id,
        'title' => 'Secret Sales Deal',
        'stage_entered_at' => now(),
    ]);

    // Unauthenticated & RBAC rejections
    $this->get('/app/projects')->assertRedirect('/login');
    $this->actingAs($zeroRole)->get('/app/projects')->assertForbidden();
    $this->actingAs($repA)->postJson('/api/internal/projects', ['name' => 'Sales Blocked'])->assertForbidden();
    $this->actingAs($supportUser)->postJson('/api/internal/projects', ['name' => 'Support Blocked'])->assertForbidden();

    // Cross-silo company/deal IDOR on Project creation rejected with 403
    $this->actingAs($pmA)->postJson('/api/internal/projects', [
        'name' => 'IDOR Company Link',
        'company_id' => $companyB->id,
    ])->assertForbidden();

    $this->actingAs($pmA)->postJson('/api/internal/projects', [
        'name' => 'IDOR Deal Link',
        'primary_deal_id' => $dealB->id,
    ])->assertForbidden();

    // Suspended account manager rejected with 422
    $this->actingAs($pmA)->postJson('/api/internal/projects', [
        'name' => 'Bad AM Project',
        'account_manager_id' => $suspendedUser->id,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['account_manager_id']);

    // PM A creates project manually with malicious link in TipTap description -> sanitized, code PRJ-YYYY-NNNN, 5 default stages
    $createRes = $this->actingAs($pmA)->postJson('/api/internal/projects', [
        'name' => 'Alpha ERP Cutover',
        'company_id' => $companyA->id,
        'primary_deal_id' => $dealA->id,
        'account_manager_id' => $amUser->id,
        'status' => ProjectStatusEnum::Active->value,
        'health' => ProjectHealthEnum::OnTrack->value,
        'start_date' => '2026-11-26',
        'target_end_date' => '2026-12-30',
        'description_json' => [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => 'Cutover plan',
                            'marks' => [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]],
                        ],
                    ],
                ],
                ['type' => 'script', 'content' => [['type' => 'text', 'text' => 'evil()']]],
            ],
        ],
    ])->assertCreated();

    $projectId = (string) $createRes->json('id');
    expect($createRes->json('code'))->toMatch('/^PRJ-\d{4}-\d{4,}$/')
        ->and($createRes->json('name'))->toBe('Alpha ERP Cutover')
        ->and($createRes->json('status'))->toBe(ProjectStatusEnum::Active->value)
        ->and($createRes->json('completed_at'))->toBeNull()
        ->and($createRes->json('department_id'))->toBe($deliveryDept->id);

    $project = Project::query()->findOrFail($projectId);
    expect($project->description_json['content'])->toHaveCount(1)
        ->and($project->description_json['content'][0]['content'][0]['marks'] ?? [])->toBe([])
        ->and($project->stages()->count())->toBe(5);

    // ProjectsIndexPage filtering (status, health, owner_id, company_id, search) + zero company_id oracle
    $pmBProjectRes = $this->actingAs($pmB)->postJson('/api/internal/projects', [
        'name' => 'PM B Isolated Project',
        'status' => ProjectStatusEnum::Planning->value,
        'health' => ProjectHealthEnum::AtRisk->value,
    ])->assertCreated();
    $pmBProjectId = (string) $pmBProjectRes->json('id');

    $this->actingAs($pmA)->get('/app/projects?status=active&health=on_track&search=ERP')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/pages/ProjectsIndexPage')
            ->has('projects.data', 1)
            ->where('projects.data.0.id', $projectId));

    // PM B filtering by Company A (which PM B cannot view) gets 0 results even if granted collaborator later or not
    $this->actingAs($pmB)->get("/app/projects?company_id={$companyA->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/pages/ProjectsIndexPage')
            ->has('projects.data', 0));

    // Admin & Superadmin parity on index & show
    foreach ([$admin, $superadmin] as $unrestricted) {
        $this->actingAs($unrestricted)->get('/app/projects')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('projects/pages/ProjectsIndexPage')
                ->has('projects.data', 2));

        $this->actingAs($unrestricted)->get("/app/projects/{$projectId}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('projects/pages/ProjectWorkspacePage')
                ->where('project.id', $projectId));
    }

    // Update status -> completed sets completed_at; transitioning away clears completed_at
    $this->actingAs($pmA)->patchJson("/api/internal/projects/{$projectId}", [
        'status' => ProjectStatusEnum::Completed->value,
        'health' => ProjectHealthEnum::AtRisk->value,
    ])->assertOk()
        ->assertJsonPath('status', ProjectStatusEnum::Completed->value);

    expect($project->fresh()->completed_at)->not->toBeNull();

    $this->actingAs($pmA)->patchJson("/api/internal/projects/{$projectId}", [
        'status' => ProjectStatusEnum::Active->value,
    ])->assertOk()
        ->assertJsonPath('status', ProjectStatusEnum::Active->value)
        ->assertJsonPath('completed_at', null);

    expect($project->fresh()->completed_at)->toBeNull();

    // Stage management: add, rename, reorder, set wip_limit, and prevent deleting stage with non-deleted tasks
    $stages = $project->stages()->orderBy('sort_order')->get()->keyBy('name');
    $todoStage = $stages['To Do'];
    $backlogStage = $stages['Backlog'];
    $doneStage = $stages['Done'];

    $taskInTodo = Task::query()->create([
        'project_id' => $project->id,
        'project_stage_id' => $todoStage->id,
        'creator_id' => $pmA->id,
        'title' => 'Blocking Stage Delete Task',
    ]);

    // Attempting to drop $todoStage (which contains non-deleted task) -> 422
    $this->actingAs($pmA)->putJson("/api/internal/projects/{$projectId}/stages", [
        'stages' => [
            ['id' => $backlogStage->id, 'name' => 'Backlog', 'category' => ProjectStageCategoryEnum::Backlog->value],
            ['id' => $doneStage->id, 'name' => 'Done', 'category' => ProjectStageCategoryEnum::Done->value],
        ],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['stages']);

    // Cross-project stage ID rejected with 422
    $otherProjectStage = Project::query()->findOrFail($pmBProjectId)->stages()->firstOrFail();
    $this->actingAs($pmA)->putJson("/api/internal/projects/{$projectId}/stages", [
        'stages' => [
            ['id' => $todoStage->id, 'name' => 'To Do', 'category' => ProjectStageCategoryEnum::Todo->value],
            ['id' => $otherProjectStage->id, 'name' => 'Hijacked', 'category' => ProjectStageCategoryEnum::InProgress->value],
        ],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['stages']);

    // Valid rename + reorder + wip_limit + add new stage + delete empty Backlog stage
    $stageUpdateRes = $this->actingAs($pmA)->putJson("/api/internal/projects/{$projectId}/stages", [
        'stages' => [
            ['id' => $todoStage->id, 'name' => 'Ready Queue', 'category' => ProjectStageCategoryEnum::Todo->value, 'wip_limit' => 5],
            ['id' => $stages['In Progress']->id, 'name' => 'Building', 'category' => ProjectStageCategoryEnum::InProgress->value, 'wip_limit' => 2],
            ['id' => $stages['Review']->id, 'name' => 'QA Review', 'category' => ProjectStageCategoryEnum::Review->value, 'wip_limit' => 3],
            ['id' => null, 'name' => 'Client UAT', 'category' => ProjectStageCategoryEnum::Review->value, 'wip_limit' => 2],
            ['id' => $doneStage->id, 'name' => 'Done', 'category' => ProjectStageCategoryEnum::Done->value, 'wip_limit' => null],
        ],
    ])->assertOk();

    expect(collect($stageUpdateRes->json('stages'))->pluck('name')->all())->toBe([
        'Ready Queue',
        'Building',
        'QA Review',
        'Client UAT',
        'Done',
    ])
        ->and($todoStage->fresh()->wip_limit)->toBe(5)
        ->and(ProjectStage::query()->whereKey($backlogStage->id)->exists())->toBeFalse();

    // Collaborator grant/revoke & company masking on workspace
    $this->actingAs($pmB)->get("/app/projects/{$projectId}")->assertForbidden();
    $this->actingAs($pmB)->patchJson("/api/internal/projects/{$projectId}", ['name' => 'IDOR Update'])->assertForbidden();
    $this->actingAs($pmB)->putJson("/api/internal/projects/{$projectId}/stages", [
        'stages' => [['id' => $todoStage->id, 'name' => 'X', 'category' => 'todo']],
    ])->assertForbidden();
    $this->actingAs($pmB)->postJson("/api/internal/projects/{$projectId}/collaborators", [
        'user_id' => $supportUser->id,
        'access_level' => CollaboratorAccessLevel::Viewer->value,
        'role_label' => CollaboratorRoleLabel::Observer->value,
    ])->assertForbidden();
    $this->actingAs($pmB)->deleteJson("/api/internal/projects/{$projectId}")->assertForbidden();

    // Grant PM B Viewer collaborator -> can view workspace, company A is masked because PM B cannot view Company A!
    $this->actingAs($pmA)->postJson("/api/internal/projects/{$projectId}/collaborators", [
        'user_id' => $pmB->id,
        'access_level' => CollaboratorAccessLevel::Viewer->value,
        'role_label' => CollaboratorRoleLabel::Observer->value,
    ])->assertCreated()
        ->assertJsonPath('user_id', $pmB->id);

    $this->actingAs($pmB)->get("/app/projects/{$projectId}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/pages/ProjectWorkspacePage')
            ->where('project.id', $projectId)
            ->where('project.company_id', null)
            ->where('project.company_name', null)
            ->where('project.has_masked_company', true)
            ->where('project.primary_deal_id', null)
            ->where('project.primary_deal_title', null)
            ->where('project.has_masked_deal', true)
            ->where('can.update', false));

    // Upgrade PM B to Editor -> can update project, still cannot manage collaborators or delete
    $this->actingAs($pmA)->postJson("/api/internal/projects/{$projectId}/collaborators", [
        'user_id' => $pmB->id,
        'access_level' => CollaboratorAccessLevel::Editor->value,
        'role_label' => CollaboratorRoleLabel::ProjectManager->value,
    ])->assertCreated();

    // Masked company_id / primary_deal_id membership oracle protection:
    // submitting hidden company_id / primary_deal_id unchanged, another out-of-silo UUID, nonexistent UUID, or null MUST return 403
    $this->actingAs($pmB)->patchJson("/api/internal/projects/{$projectId}", ['company_id' => $companyA->id])->assertForbidden();
    $this->actingAs($pmB)->patchJson("/api/internal/projects/{$projectId}", ['company_id' => $companyB->id])->assertForbidden();
    $this->actingAs($pmB)->patchJson("/api/internal/projects/{$projectId}", ['company_id' => (string) Str::uuid7()])->assertForbidden();
    $this->actingAs($pmB)->patchJson("/api/internal/projects/{$projectId}", ['company_id' => null])->assertForbidden();

    $this->actingAs($pmB)->patchJson("/api/internal/projects/{$projectId}", ['primary_deal_id' => $dealA->id])->assertForbidden();
    $this->actingAs($pmB)->patchJson("/api/internal/projects/{$projectId}", ['primary_deal_id' => $dealB->id])->assertForbidden();
    $this->actingAs($pmB)->patchJson("/api/internal/projects/{$projectId}", ['primary_deal_id' => (string) Str::uuid7()])->assertForbidden();
    $this->actingAs($pmB)->patchJson("/api/internal/projects/{$projectId}", ['primary_deal_id' => null])->assertForbidden();

    // Updating name/status/health WITHOUT sending company_id/primary_deal_id succeeds (200) and preserves masked links
    $this->actingAs($pmB)->patchJson("/api/internal/projects/{$projectId}", [
        'name' => 'Alpha ERP Phase 1',
        'status' => ProjectStatusEnum::Active->value,
        'health' => ProjectHealthEnum::AtRisk->value,
    ])->assertOk()
        ->assertJsonPath('name', 'Alpha ERP Phase 1')
        ->assertJsonPath('company_id', null)
        ->assertJsonPath('has_masked_company', true)
        ->assertJsonPath('primary_deal_id', null)
        ->assertJsonPath('has_masked_deal', true);

    expect($project->fresh()->company_id)->toBe($companyA->id)
        ->and($project->fresh()->primary_deal_id)->toBe($dealA->id);

    $this->actingAs($pmB)->deleteJson("/api/internal/projects/{$projectId}")->assertForbidden();

    // Revoke PM B -> denied again
    $this->actingAs($pmA)->deleteJson("/api/internal/projects/{$projectId}/collaborators/{$pmB->id}")->assertOk();
    $this->actingAs($pmB)->get("/app/projects/{$projectId}")->assertForbidden();

    // Delete project (Owner / Director / Admin)
    $this->actingAs($directorA)->deleteJson("/api/internal/projects/{$projectId}")
        ->assertOk()
        ->assertJsonPath('deleted', true);
    expect(Project::withTrashed()->find($projectId)?->trashed())->toBeTrue();

    unset($taskInTodo);
});

it('filters project, task, and deal_handoff timeline entries strictly by viewer visibility and permissions', function (): void {
    $dept = Department::query()->create(['name' => 'Ops', 'slug' => 'pt-timeline']);

    $admin = createProjectsFeatureUser($dept, 'EMP-TL-ADM', 'adm.tl@acme.internal', RoleEnum::Admin);
    $repA = createProjectsFeatureUser($dept, 'EMP-TL-REPA', 'rep.a.tl@acme.internal', RoleEnum::Sales);
    $repB = createProjectsFeatureUser($dept, 'EMP-TL-REPB', 'rep.b.tl@acme.internal', RoleEnum::Sales);
    $pmA = createProjectsFeatureUser($dept, 'EMP-TL-PMA', 'pm.a.tl@acme.internal', RoleEnum::ProjectManager);
    $pmB = createProjectsFeatureUser($dept, 'EMP-TL-PMB', 'pm.b.tl@acme.internal', RoleEnum::ProjectManager);
    $itUser = createProjectsFeatureUser($dept, 'EMP-TL-IT', 'it.tl@acme.internal', RoleEnum::It);

    $pipeline = Pipeline::query()->where('slug', 'new-business')->firstOrFail();
    $stage = $pipeline->stages()->orderBy('sort_order')->firstOrFail();

    $sharedCompany = Company::query()->create(['name' => 'Shared Client Co', 'owner_id' => $repA->id]);
    app(GrantEntityCollaboratorAction::class)->execute(
        collaboratable: $sharedCompany,
        user: $repB,
        accessLevel: CollaboratorAccessLevel::Viewer,
        roleLabel: CollaboratorRoleLabel::Observer,
        grantedBy: $repA,
    );
    app(GrantEntityCollaboratorAction::class)->execute(
        collaboratable: $sharedCompany,
        user: $itUser,
        accessLevel: CollaboratorAccessLevel::Viewer,
        roleLabel: CollaboratorRoleLabel::ItLead,
        grantedBy: $repA,
    );

    $dealA = Deal::query()->create([
        'company_id' => $sharedCompany->id,
        'pipeline_id' => $pipeline->id,
        'stage_id' => $stage->id,
        'owner_id' => $repA->id,
        'title' => 'Deal A',
        'stage_entered_at' => now(),
    ]);

    // Visible Project A + Task A for PM A & Rep A (grant Rep A Viewer on Project A)
    $projectA = Project::query()->create([
        'code' => 'PRJ-2026-8101',
        'company_id' => $sharedCompany->id,
        'primary_deal_id' => $dealA->id,
        'owner_id' => $pmA->id,
        'department_id' => $dept->id,
        'name' => 'Project A Visible',
    ]);
    app(GrantEntityCollaboratorAction::class)->execute(
        collaboratable: $projectA,
        user: $repA,
        accessLevel: CollaboratorAccessLevel::Viewer,
        roleLabel: CollaboratorRoleLabel::Observer,
        grantedBy: $pmA,
    );

    // Cross-silo Project B + Task B (PM B only) linked to sharedCompany
    $projectB = Project::query()->create([
        'code' => 'PRJ-2026-8102',
        'company_id' => $sharedCompany->id,
        'owner_id' => $pmB->id,
        'department_id' => $dept->id,
        'name' => 'Project B Secret',
    ]);

    $taskA = Task::query()->create([
        'project_id' => $projectA->id,
        'linkable_type' => 'company',
        'linkable_id' => $sharedCompany->id,
        'creator_id' => $pmA->id,
        'title' => 'Visible Task A',
    ]);
    $taskB = Task::query()->create([
        'project_id' => $projectB->id,
        'linkable_type' => 'company',
        'linkable_id' => $sharedCompany->id,
        'creator_id' => $pmB->id,
        'title' => 'Secret Task B',
    ]);
    $standaloneTaskA = Task::query()->create([
        'project_id' => null,
        'linkable_type' => 'deal',
        'linkable_id' => $dealA->id,
        'creator_id' => $repA->id,
        'title' => 'Deal A Followup',
    ]);

    $handoffA = DealHandoff::query()->create([
        'deal_id' => $dealA->id,
        'company_id' => $sharedCompany->id,
        'submitted_by_id' => $repA->id,
        'assigned_pm_id' => $pmA->id,
        'status' => DealHandoffStatusEnum::PendingReview,
        'scope_notes_json' => ['type' => 'doc', 'content' => []],
        'submitted_at' => now(),
    ]);

    $recordActivity = app(RecordActivityAction::class);
    $t = now()->toImmutable()->subMinutes(10);

    $recordActivity->execute($pmA, ActivityTypeEnum::ProjectProvisioned, 'project', $projectA->id, ['company' => $sharedCompany->id, 'project' => $projectA->id], 'Project A provisioned', [], 'source_policy', $t->addSeconds(1));
    $recordActivity->execute($pmB, ActivityTypeEnum::ProjectProvisioned, 'project', $projectB->id, ['company' => $sharedCompany->id, 'project' => $projectB->id], 'Secret Project B provisioned', [], 'source_policy', $t->addSeconds(2));
    $recordActivity->execute($pmA, ActivityTypeEnum::TaskCreated, 'task', $taskA->id, ['company' => $sharedCompany->id, 'project' => $projectA->id], 'Visible Task A created', [], 'source_policy', $t->addSeconds(3));
    $recordActivity->execute($pmB, ActivityTypeEnum::TaskCreated, 'task', $taskB->id, ['company' => $sharedCompany->id, 'project' => $projectB->id], 'Secret Task B created', [], 'source_policy', $t->addSeconds(4));
    $recordActivity->execute($repA, ActivityTypeEnum::TaskCreated, 'task', $standaloneTaskA->id, ['company' => $sharedCompany->id, 'deal' => $dealA->id], 'Deal A Followup created', [], 'source_policy', $t->addSeconds(5));
    $recordActivity->execute($repA, ActivityTypeEnum::HandoffSubmitted, 'deal_handoff', $handoffA->id, ['company' => $sharedCompany->id, 'deal' => $dealA->id], 'Deal A Handoff submitted', [], 'source_policy', $t->addSeconds(6));

    // Rep A can query Project A timeline directly
    $this->actingAs($repA)
        ->getJson("/api/internal/crm/timeline?linkable_type=project&linkable_id={$projectA->id}")
        ->assertOk()
        ->assertJsonCount(2, 'items');

    // Rep B / PM B cannot query Project A timeline (403)
    $this->actingAs($pmB)
        ->getJson("/api/internal/crm/timeline?linkable_type=project&linkable_id={$projectA->id}")
        ->assertForbidden();

    // Rep A queries sharedCompany timeline: sees Project A, Task A, Deal A Followup, Deal A Handoff; NEVER Project B or Task B
    $repACompanySummaries = collect(
        app(QueryEntityTimelineAction::class)->execute($repA, 'company', $sharedCompany->id, null, 50)->items
    )->pluck('summary')->all();

    expect($repACompanySummaries)->toEqualCanonicalizing([
        'Project A provisioned',
        'Visible Task A created',
        'Deal A Followup created',
        'Deal A Handoff submitted',
    ]);

    // Rep B queries sharedCompany timeline: sees ZERO of Silo A's project, tasks, or handoff!
    $repBCompanyPage = app(QueryEntityTimelineAction::class)->execute($repB, 'company', $sharedCompany->id, null, 50);
    expect($repBCompanyPage->items)->toBeEmpty();

    // IT user (lacks handoffs.view and deals.view) sees ZERO handoffs or Deal A tasks on sharedCompany!
    $itCompanyPage = app(QueryEntityTimelineAction::class)->execute($itUser, 'company', $sharedCompany->id, null, 50);
    expect($itCompanyPage->items)->toBeEmpty();

    // Admin sees all 6 entries on sharedCompany
    $adminCompanyPage = app(QueryEntityTimelineAction::class)->execute($admin, 'company', $sharedCompany->id, null, 50);
    expect($adminCompanyPage->items)->toHaveCount(6);
});

it('creates/updates/deletes project & standalone CRM tasks, enforces 1-level subtask depth, watchers, no oracle leaks, and strict My Work scoping', function (): void {
    $salesDept = Department::query()->create(['name' => 'Sales', 'slug' => 'pt-tasks-sales']);
    $delivDept = Department::query()->create(['name' => 'Delivery', 'slug' => 'pt-tasks-deliv']);

    $directorA = createProjectsFeatureUser($salesDept, 'EMP-TK-DIRA', 'dir.a.tk@acme.internal', RoleEnum::Director);
    $repA = createProjectsFeatureUser($salesDept, 'EMP-TK-REPA', 'rep.a.tk@acme.internal', RoleEnum::Sales, $directorA);
    $repB = createProjectsFeatureUser($salesDept, 'EMP-TK-REPB', 'rep.b.tk@acme.internal', RoleEnum::Sales);
    $pmA = createProjectsFeatureUser($delivDept, 'EMP-TK-PMA', 'pm.a.tk@acme.internal', RoleEnum::ProjectManager);
    $pmB = createProjectsFeatureUser($delivDept, 'EMP-TK-PMB', 'pm.b.tk@acme.internal', RoleEnum::ProjectManager);
    $supportUser = createProjectsFeatureUser($delivDept, 'EMP-TK-SUP', 'sup.tk@acme.internal', RoleEnum::Support);
    $suspendedUser = createProjectsFeatureUser($delivDept, 'EMP-TK-SUSP', 'susp.tk@acme.internal', RoleEnum::ProjectManager, null, UserStatusEnum::Suspended);

    $pipeline = Pipeline::query()->where('slug', 'new-business')->firstOrFail();
    $dealStage = $pipeline->stages()->orderBy('sort_order')->firstOrFail();

    $companyA = Company::query()->create(['name' => 'Silo A Acme', 'owner_id' => $repA->id]);
    $contactA = Contact::query()->create(['company_id' => $companyA->id, 'owner_id' => $repA->id, 'first_name' => 'Ada', 'last_name' => 'Lovelace']);
    $dealA = Deal::query()->create([
        'company_id' => $companyA->id,
        'primary_contact_id' => $contactA->id,
        'pipeline_id' => $pipeline->id,
        'stage_id' => $dealStage->id,
        'owner_id' => $repA->id,
        'title' => 'Silo A Deal',
        'stage_entered_at' => now(),
    ]);

    $companyB = Company::query()->create(['name' => 'Silo B Globex', 'owner_id' => $repB->id]);
    $dealB = Deal::query()->create([
        'company_id' => $companyB->id,
        'pipeline_id' => $pipeline->id,
        'stage_id' => $dealStage->id,
        'owner_id' => $repB->id,
        'title' => 'Silo B Deal',
        'stage_entered_at' => now(),
    ]);

    $project = Project::query()->create([
        'code' => 'PRJ-2026-8201',
        'owner_id' => $pmA->id,
        'department_id' => $delivDept->id,
        'name' => 'Task Lifecycle Project',
    ]);
    $todoStage = ProjectStage::query()->create([
        'project_id' => $project->id,
        'name' => 'To Do',
        'category' => ProjectStageCategoryEnum::Todo,
        'sort_order' => 1,
        'wip_limit' => 2,
    ]);
    $otherProject = Project::query()->create([
        'code' => 'PRJ-2026-8202',
        'owner_id' => $pmB->id,
        'department_id' => $delivDept->id,
        'name' => 'PM B Project',
    ]);
    $otherProjectStage = ProjectStage::query()->create([
        'project_id' => $otherProject->id,
        'name' => 'To Do',
        'category' => ProjectStageCategoryEnum::Todo,
        'sort_order' => 1,
    ]);

    // 1. Cross-silo & nonexistent targets return identical 403 (zero membership/existence oracle leaks)
    $nonexistentUuid = (string) Str::uuid7();
    $this->actingAs($pmB)->postJson('/api/internal/tasks', [
        'project_id' => $project->id,
        'title' => 'Cross Silo Project Task',
    ])->assertForbidden();
    $this->actingAs($pmB)->postJson('/api/internal/tasks', [
        'project_id' => $nonexistentUuid,
        'title' => 'Missing Project Task',
    ])->assertForbidden();

    $this->actingAs($pmB)->postJson('/api/internal/tasks', [
        'linkable_type' => 'deal',
        'linkable_id' => $dealA->id,
        'title' => 'Cross Silo Deal Task',
    ])->assertForbidden();
    $this->actingAs($pmB)->postJson('/api/internal/tasks', [
        'linkable_type' => 'deal',
        'linkable_id' => $nonexistentUuid,
        'title' => 'Missing Deal Task',
    ])->assertForbidden();

    // Cross-project stage rejected with 422
    $this->actingAs($pmA)->postJson('/api/internal/tasks', [
        'project_id' => $project->id,
        'project_stage_id' => $otherProjectStage->id,
        'title' => 'Wrong Stage Project',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['project_stage_id']);

    // Inactive assignee rejected with 422
    $this->actingAs($pmA)->postJson('/api/internal/tasks', [
        'project_id' => $project->id,
        'project_stage_id' => $todoStage->id,
        'assignee_id' => $suspendedUser->id,
        'title' => 'Suspended Assignee',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['assignee_id']);

    // Create parent task + 1st-level subtask (hits WIP limit = 2)
    $parentRes = $this->actingAs($pmA)->postJson('/api/internal/tasks', [
        'project_id' => $project->id,
        'project_stage_id' => $todoStage->id,
        'assignee_id' => $pmA->id,
        'title' => 'Parent Task',
        'priority' => TaskPriorityEnum::High->value,
        'estimated_minutes' => 120,
    ])->assertCreated()
        ->assertJsonPath('version', 1)
        ->assertJsonPath('board_position', 65536);
    $parentTaskId = (string) $parentRes->json('id');

    $subtaskRes = $this->actingAs($pmA)->postJson('/api/internal/tasks', [
        'project_id' => $project->id,
        'project_stage_id' => $todoStage->id,
        'parent_task_id' => $parentTaskId,
        'assignee_id' => $pmA->id,
        'title' => 'Level 1 Subtask',
    ])->assertCreated();
    $subtaskId = (string) $subtaskRes->json('id');

    // WIP limit blocks 3rd task in $todoStage with 422
    $this->actingAs($pmA)->postJson('/api/internal/tasks', [
        'project_id' => $project->id,
        'project_stage_id' => $todoStage->id,
        'title' => 'Over WIP Task',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['project_stage_id']);

    // Raise WIP limit so depth check runs cleanly, then verify 2nd-level subtask returns 422
    $todoStage->update(['wip_limit' => 10]);

    // Unauthorized caller probing a subtask gets 403 BEFORE 422 depth error (no depth oracle!)
    $this->actingAs($pmB)->postJson('/api/internal/tasks', [
        'parent_task_id' => $subtaskId,
        'title' => 'Cross Silo Subtask Probe',
    ])->assertForbidden();

    // Authorized PM A attempting 2nd-level subtask gets 422!
    $this->actingAs($pmA)->postJson('/api/internal/tasks', [
        'project_id' => $project->id,
        'project_stage_id' => $todoStage->id,
        'parent_task_id' => $subtaskId,
        'title' => 'Level 2 Nested Subtask',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['parent_task_id']);

    // Verify TaskCreated activity recorded on Project
    expect(Activity::query()
        ->where('source_type', 'task')
        ->where('source_id', $parentTaskId)
        ->where('activity_type', ActivityTypeEnum::TaskCreated->value)
        ->exists())->toBeTrue();
    expect(ActivityLink::query()
        ->where('linkable_type', 'project')
        ->where('linkable_id', $project->id)
        ->exists())->toBeTrue();

    // Watchers: cross-silo denied (403); suspended target rejected (422); PM A toggles self and adds Support collaborator
    $this->actingAs($pmB)->postJson("/api/internal/tasks/{$parentTaskId}/watchers", [])->assertForbidden();
    $this->actingAs($pmA)->postJson("/api/internal/tasks/{$parentTaskId}/watchers", [
        'user_id' => $suspendedUser->id,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['user_id']);

    $this->actingAs($pmA)->postJson("/api/internal/tasks/{$parentTaskId}/watchers", [])
        ->assertOk()
        ->assertJsonPath('is_watching', true)
        ->assertJsonPath('watchers_count', 1);

    $this->actingAs($pmA)->postJson("/api/internal/tasks/{$parentTaskId}/watchers", [
        'user_id' => $supportUser->id,
        'watching' => true,
    ])->assertOk()
        ->assertJsonPath('watchers_count', 2);

    $this->actingAs($pmA)->postJson("/api/internal/tasks/{$parentTaskId}/watchers", [])
        ->assertOk()
        ->assertJsonPath('is_watching', false)
        ->assertJsonPath('watchers_count', 1);

    // Grant Viewer collaborator to Support -> Support can toggle SELF watcher, CANNOT add/remove OTHER user
    app(GrantEntityCollaboratorAction::class)->execute(
        collaboratable: $project,
        user: $supportUser,
        accessLevel: CollaboratorAccessLevel::Viewer,
        roleLabel: CollaboratorRoleLabel::SupportLead,
        grantedBy: $pmA,
    );
    $this->actingAs($supportUser)->postJson("/api/internal/tasks/{$parentTaskId}/watchers", [
        'user_id' => $pmA->id,
        'watching' => true,
    ])->assertForbidden();

    // Update task to done -> sets completed_at + records TaskCompleted; revert to in_progress -> clears completed_at
    $this->actingAs($pmA)->patchJson("/api/internal/tasks/{$parentTaskId}", [
        'expected_version' => 1,
        'status' => TaskStatusEnum::Done->value,
    ])->assertOk()
        ->assertJsonPath('version', 2)
        ->assertJsonPath('status', TaskStatusEnum::Done->value);
    expect(Task::query()->findOrFail($parentTaskId)->completed_at)->not->toBeNull()
        ->and(Activity::query()->where('source_id', $parentTaskId)->where('activity_type', ActivityTypeEnum::TaskCompleted->value)->count())->toBe(1);

    $this->actingAs($pmA)->patchJson("/api/internal/tasks/{$parentTaskId}", [
        'expected_version' => 2,
        'status' => TaskStatusEnum::InProgress->value,
    ])->assertOk()
        ->assertJsonPath('version', 3)
        ->assertJsonPath('completed_at', null);

    // 2. Standalone CRM tasks linked to company/contact/deal + ListMyWorkAction strict grouping & scoping
    $this->actingAs($repA)->postJson('/api/internal/tasks', [
        'linkable_type' => 'deal',
        'linkable_id' => $dealA->id,
        'title' => 'Sales lacks tasks.create',
    ])->assertForbidden();

    Carbon::setTestNow(CarbonImmutable::parse('2026-11-26 10:00:00', 'UTC'));

    $standaloneDealTaskId = (string) $this->actingAs($directorA)->postJson('/api/internal/tasks', [
        'linkable_type' => 'deal',
        'linkable_id' => $dealA->id,
        'assignee_id' => $pmA->id,
        'title' => 'Standalone Deal Follow-up',
        'due_at' => '2026-11-26 16:00:00',
    ])->assertCreated()->json('id');

    $standaloneContactTaskId = (string) $this->actingAs($directorA)->postJson('/api/internal/tasks', [
        'linkable_type' => 'contact',
        'linkable_id' => $contactA->id,
        'assignee_id' => $pmA->id,
        'title' => 'Overdue Contact Call',
        'due_at' => '2026-11-24 12:00:00',
    ])->assertCreated()->json('id');

    $thisWeekTaskId = (string) $this->actingAs($pmA)->postJson('/api/internal/tasks', [
        'project_id' => $project->id,
        'project_stage_id' => $todoStage->id,
        'assignee_id' => $pmA->id,
        'title' => 'This Week Delivery Task',
        'due_at' => '2026-11-30 12:00:00',
    ])->assertCreated()->json('id');

    $laterFutureTaskId = (string) $this->actingAs($pmA)->postJson('/api/internal/tasks', [
        'project_id' => $project->id,
        'project_stage_id' => $todoStage->id,
        'assignee_id' => $pmA->id,
        'title' => 'Future Milestone',
        'due_at' => '2026-12-20 12:00:00',
    ])->assertCreated()->json('id');

    // $parentTaskId & $subtaskId have no due_at -> folded into 'later'
    // Also create a done task, a cancelled task, and another user's task (all must be excluded from PM A's My Work)
    Task::query()->create([
        'project_id' => $project->id,
        'project_stage_id' => $todoStage->id,
        'creator_id' => $pmA->id,
        'assignee_id' => $pmA->id,
        'title' => 'Completed Excluded',
        'status' => TaskStatusEnum::Done,
        'due_at' => '2026-11-25 12:00:00',
    ]);
    Task::query()->create([
        'project_id' => $project->id,
        'project_stage_id' => $todoStage->id,
        'creator_id' => $pmA->id,
        'assignee_id' => $pmA->id,
        'title' => 'Cancelled Excluded',
        'status' => TaskStatusEnum::Cancelled,
        'due_at' => '2026-11-25 12:00:00',
    ]);
    Task::query()->create([
        'project_id' => $otherProject->id,
        'project_stage_id' => $otherProjectStage->id,
        'creator_id' => $pmB->id,
        'assignee_id' => $pmB->id,
        'title' => 'PM B Task Excluded',
        'status' => TaskStatusEnum::Todo,
        'due_at' => '2026-11-25 12:00:00',
    ]);

    // IDOR on standalone task endpoints
    $this->actingAs($pmB)->patchJson("/api/internal/tasks/{$standaloneDealTaskId}", [
        'expected_version' => 1,
        'title' => 'Hijack',
    ])->assertForbidden();
    $this->actingAs($pmB)->deleteJson("/api/internal/tasks/{$standaloneDealTaskId}")->assertForbidden();
    $this->actingAs($pmB)->postJson("/api/internal/tasks/{$standaloneDealTaskId}/watchers", [])->assertForbidden();

    // Attempting to move standalone task via MoveTaskAction -> 422
    $this->actingAs($pmA)->patchJson("/api/internal/tasks/{$standaloneDealTaskId}/move", [
        'target_stage_id' => $todoStage->id,
        'expected_version' => 1,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['target_stage_id']);

    // Verify ListMyWorkAction & GET /app/my-work strictly scoped to viewer ($pmA vs $pmB)
    $myWorkGroups = collect(app(ListMyWorkAction::class)->execute($pmA)['groups'])->keyBy('key');

    expect(collect($myWorkGroups->get('overdue')->tasks)->pluck('id')->all())->toBe([$standaloneContactTaskId])
        ->and(collect($myWorkGroups->get('today')->tasks)->pluck('id')->all())->toBe([$standaloneDealTaskId])
        ->and(collect($myWorkGroups->get('this_week')->tasks)->pluck('id')->all())->toBe([$thisWeekTaskId])
        ->and(collect($myWorkGroups->get('later')->tasks)->pluck('id')->all())->toBe([
            $laterFutureTaskId,
            $parentTaskId,
            $subtaskId,
        ]);

    // Note: standalone tasks mask linkable_id if viewer cannot view linkable, and keep it if viewer can;
    // here $pmA has no Deal A view access unless granted, so linkable_id is masked on standaloneDealTaskId!
    $todayTaskDto = $myWorkGroups->get('today')->tasks[0];
    expect($todayTaskDto->has_masked_linkable)->toBeTrue()
        ->and($todayTaskDto->linkable_id)->toBeNull();

    // PM B's My Work ONLY contains PM B's task
    $this->actingAs($pmB)->get('/app/my-work')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/pages/MyWorkPage')
            ->where('totalCount', 1)
            ->where('groups.0.tasks.0.title', 'PM B Task Excluded'));

    // Deleting parent task soft-deletes both parent and its child subtask
    $this->actingAs($pmA)->deleteJson("/api/internal/tasks/{$parentTaskId}")
        ->assertOk()
        ->assertJsonPath('deleted', true);

    expect(Task::withTrashed()->find($parentTaskId)?->trashed())->toBeTrue()
        ->and(Task::withTrashed()->find($subtaskId)?->trashed())->toBeTrue();
    unset($dealB);
});
