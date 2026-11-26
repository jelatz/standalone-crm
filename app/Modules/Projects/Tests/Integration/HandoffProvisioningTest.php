<?php

use App\Modules\Core\AsyncJobs\Enums\AsyncJobStatus;
use App\Modules\Core\AsyncJobs\Models\AsyncJobRun;
use App\Modules\Core\Rbac\Enums\CollaboratorAccessLevel;
use App\Modules\Core\Rbac\Enums\CollaboratorRoleLabel;
use App\Modules\Core\Rbac\Enums\RoleEnum;
use App\Modules\Core\Rbac\Models\EntityCollaborator;
use App\Modules\Core\Users\Actions\RebuildUserHierarchyClosureAction;
use App\Modules\Core\Users\Enums\UserStatusEnum;
use App\Modules\Core\Users\Models\Department;
use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Companies\Models\Company;
use App\Modules\Crm\Deals\Models\Deal;
use App\Modules\Crm\Pipelines\Enums\PipelineStageTypeEnum;
use App\Modules\Crm\Pipelines\Models\Pipeline;
use App\Modules\Crm\Timeline\Enums\ActivityTypeEnum;
use App\Modules\Crm\Timeline\Models\Activity;
use App\Modules\Crm\Timeline\Models\ActivityLink;
use App\Modules\Projects\Handoffs\Actions\ApproveDealHandoffAction;
use App\Modules\Projects\Handoffs\Actions\ProvisionProjectFromHandoffAction;
use App\Modules\Projects\Handoffs\Actions\RejectDealHandoffAction;
use App\Modules\Projects\Handoffs\Actions\RequestHandoffChangesAction;
use App\Modules\Projects\Handoffs\Actions\ResubmitDealHandoffAction;
use App\Modules\Projects\Handoffs\Actions\SubmitDealHandoffAction;
use App\Modules\Projects\Handoffs\Data\ApproveHandoffData;
use App\Modules\Projects\Handoffs\Data\ReviewHandoffData;
use App\Modules\Projects\Handoffs\Data\SubmitHandoffData;
use App\Modules\Projects\Handoffs\Enums\DealHandoffStatusEnum;
use App\Modules\Projects\Handoffs\Events\HandoffStatusChanged;
use App\Modules\Projects\Handoffs\Jobs\ProvisionProjectFromHandoffJob;
use App\Modules\Projects\Handoffs\Models\DealHandoff;
use App\Modules\Projects\Projects\Enums\ProjectHealthEnum;
use App\Modules\Projects\Projects\Enums\ProjectStatusEnum;
use App\Modules\Projects\Projects\Models\Project;
use App\Modules\Projects\Projects\Models\ProjectStage;
use App\Modules\Projects\Tasks\Models\Task;
use App\Modules\Projects\Templates\Enums\TemplateAssigneeRoleHintEnum;
use App\Modules\Projects\Templates\Models\ProjectTemplate;
use App\Modules\Projects\Templates\Models\ProjectTemplateTask;
use Carbon\CarbonImmutable;
use Database\Seeders\CrmDefaultsSeeder;
use Database\Seeders\ProjectDefaultsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

uses(TestCase::class, DatabaseTruncation::class);

$createProvisioningUser = static function (
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
};

beforeEach(function (): void {
    Cache::flush();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(CrmDefaultsSeeder::class);
    $this->seed(ProjectDefaultsSeeder::class);
});

afterEach(function (): void {
    Cache::flush();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->truncateTablesForAllConnections();
});

it('runs full end-to-end Deal Won -> submit -> changes_requested -> resubmit -> approve -> provision with stages, parent/child tasks, collaborators, activities, and strict idempotency', function () use ($createProvisioningUser): void {
    $salesDept = Department::query()->create(['name' => 'Sales', 'slug' => 'prov-sales']);
    $deliveryDept = Department::query()->create(['name' => 'Delivery', 'slug' => 'prov-delivery']);

    $repA = $createProvisioningUser($salesDept, 'EMP-PR-REPA', 'rep.pr@acme.internal', RoleEnum::Sales);
    $opsMgr = $createProvisioningUser($deliveryDept, 'EMP-PR-OPS', 'ops.pr@acme.internal', RoleEnum::OperationsManager);
    $pm = $createProvisioningUser($deliveryDept, 'EMP-PR-PM', 'pm.pr@acme.internal', RoleEnum::ProjectManager, $opsMgr);
    $am = $createProvisioningUser($salesDept, 'EMP-PR-AM', 'am.pr@acme.internal', RoleEnum::AccountManager);
    $suspendedOps = $createProvisioningUser($deliveryDept, 'EMP-PR-SOPS', 'sops.pr@acme.internal', RoleEnum::OperationsManager, null, UserStatusEnum::Suspended);
    $trashedOps = $createProvisioningUser($deliveryDept, 'EMP-PR-TOPS', 'tops.pr@acme.internal', RoleEnum::OperationsManager);
    $trashedOps->delete();

    $pipeline = Pipeline::query()->where('slug', 'new-business')->firstOrFail();
    $wonStage = $pipeline->stages()->where('stage_type', PipelineStageTypeEnum::Won->value)->firstOrFail();
    $template = ProjectTemplate::query()->where('name', 'Standard Client Onboarding')->firstOrFail();

    $company = Company::query()->create([
        'name' => 'Northwind Logistics',
        'owner_id' => $repA->id,
    ]);

    $deal = Deal::query()->create([
        'company_id' => $company->id,
        'pipeline_id' => $pipeline->id,
        'stage_id' => $wonStage->id,
        'owner_id' => $repA->id,
        'title' => 'Northwind Global WMS Rollout',
        'amount_cents' => 4500000,
        'currency' => 'USD',
        'stage_entered_at' => now(),
        'won_at' => now(),
    ]);

    // 1. Submit: broadcasts ID-only HandoffStatusChanged ONLY to active handoffs.review users
    Event::fake([HandoffStatusChanged::class]);

    $handoffId = $this->actingAs($repA)
        ->postJson("/api/internal/crm/deals/{$deal->id}/handoffs", [
            'scope_notes_json' => [
                'type' => 'doc',
                'content' => [
                    [
                        'type' => 'paragraph',
                        'content' => [['type' => 'text', 'text' => 'Deliver multi-warehouse WMS integration.']],
                    ],
                ],
            ],
            'handoff_checklist' => [['label' => 'Signed SOW', 'checked' => true]],
            'target_kickoff_date' => '2026-12-10',
        ])
        ->assertCreated()
        ->json('id');

    $submitEvents = Event::dispatched(HandoffStatusChanged::class);
    $submitRecipients = $submitEvents->map(static fn (array $e): string => $e[0]->recipientUserId)->all();

    expect($submitRecipients)->toContain($opsMgr->id)
        ->and($submitRecipients)->not->toContain($repA->id, $pm->id, $am->id, $suspendedOps->id, $trashedOps->id);

    foreach ($submitEvents as [$event]) {
        expect($event)->toBeInstanceOf(HandoffStatusChanged::class)
            ->and($event->broadcastOn())->toHaveCount(1)
            ->and($event->broadcastOn()[0])->toBeInstanceOf(PrivateChannel::class)
            ->and($event->broadcastOn()[0]->name)->toBe("private-user.{$event->recipientUserId}")
            ->and($event->broadcastWith())->toBe([
                'handoff_id' => $handoffId,
                'status' => DealHandoffStatusEnum::PendingReview->value,
                'deal_id' => $deal->id,
            ])
            ->and(array_keys($event->broadcastWith()))->not->toContain('title', 'deal_title', 'company_name');
    }

    // 2. Request changes -> broadcasts to submitter (PM/AM not yet assigned)
    Event::fake([HandoffStatusChanged::class]);

    $this->actingAs($opsMgr)
        ->postJson("/api/internal/handoffs/{$handoffId}/request-changes", [
            'review_feedback' => 'Specify warehouse count.',
        ])
        ->assertOk();

    $changesEvents = Event::dispatched(HandoffStatusChanged::class);
    expect($changesEvents->map(static fn (array $e): string => $e[0]->recipientUserId)->all())->toBe([$repA->id])
        ->and($changesEvents[0][0]->broadcastWith()['status'])->toBe(DealHandoffStatusEnum::ChangesRequested->value);

    // 3. Resubmit -> broadcasts to active handoffs.review users
    Event::fake([HandoffStatusChanged::class]);

    $this->actingAs($repA)
        ->postJson("/api/internal/handoffs/{$handoffId}/resubmit", [
            'scope_notes_json' => [
                'type' => 'doc',
                'content' => [
                    [
                        'type' => 'paragraph',
                        'content' => [['type' => 'text', 'text' => 'Deliver 4-warehouse WMS integration.']],
                    ],
                ],
            ],
            'handoff_checklist' => [
                ['label' => 'Signed SOW', 'checked' => true],
                ['label' => '4 warehouses confirmed', 'checked' => true],
            ],
            'target_kickoff_date' => '2026-12-15',
            'assigned_am_id' => $am->id,
        ])
        ->assertOk();

    $resubmitRecipients = Event::dispatched(HandoffStatusChanged::class)
        ->map(static fn (array $e): string => $e[0]->recipientUserId)
        ->all();
    expect($resubmitRecipients)->toContain($opsMgr->id)
        ->and($resubmitRecipients)->not->toContain($repA->id, $suspendedOps->id, $trashedOps->id);

    // 4. Approve -> afterCommit dispatches ProvisionProjectFromHandoffJob (sync) -> Provisions Project + broadcasts Approved + Provisioned
    Event::fake([HandoffStatusChanged::class]);

    $approveResponse = $this->actingAs($opsMgr)
        ->postJson("/api/internal/handoffs/{$handoffId}/approve", [
            'project_template_id' => $template->id,
            'assigned_pm_id' => $pm->id,
            'assigned_am_id' => $am->id,
            'project_name' => 'Northwind WMS Phase 1',
            'target_kickoff_date' => '2026-12-15',
            'review_feedback' => 'Cleared for launch.',
        ])
        ->assertOk();

    expect($approveResponse->json('status'))->toBe(DealHandoffStatusEnum::Provisioned->value)
        ->and($approveResponse->json('provisioned_project.name'))->toBe('Northwind WMS Phase 1');

    // Verify HandoffStatusChanged broadcasts for Approved then Provisioned to [repA, pm, am]
    $lifecycleEvents = Event::dispatched(HandoffStatusChanged::class);
    $approvedRecipients = $lifecycleEvents
        ->filter(static fn (array $e): bool => $e[0]->status === DealHandoffStatusEnum::Approved)
        ->map(static fn (array $e): string => $e[0]->recipientUserId)
        ->values()
        ->all();
    $provisionedRecipients = $lifecycleEvents
        ->filter(static fn (array $e): bool => $e[0]->status === DealHandoffStatusEnum::Provisioned)
        ->map(static fn (array $e): string => $e[0]->recipientUserId)
        ->values()
        ->all();

    expect($approvedRecipients)->toEqualCanonicalizing([$repA->id, $pm->id, $am->id])
        ->and($provisionedRecipients)->toEqualCanonicalizing([$repA->id, $pm->id, $am->id]);

    // Verify AsyncJobRun lifecycle completed with idempotency key
    $jobRun = AsyncJobRun::query()
        ->where('idempotency_key', "handoff-provision:{$handoffId}")
        ->firstOrFail();
    expect($jobRun->status)->toBe(AsyncJobStatus::Completed)
        ->and($jobRun->subject_type)->toBe('deal_handoff')
        ->and($jobRun->subject_id)->toBe($handoffId)
        ->and($jobRun->triggered_by_id)->toBe($opsMgr->id)
        ->and($jobRun->payload_summary)->toBe(['deal_id' => $deal->id]);

    // Verify Project attributes
    $project = Project::query()->where('deal_handoff_id', $handoffId)->firstOrFail();
    expect($project->code)->toMatch('/^PRJ-\d{4}-\d{4,}$/')
        ->and($project->name)->toBe('Northwind WMS Phase 1')
        ->and($project->company_id)->toBe($company->id)
        ->and($project->primary_deal_id)->toBe($deal->id)
        ->and($project->owner_id)->toBe($pm->id)
        ->and($project->account_manager_id)->toBe($am->id)
        ->and($project->department_id)->toBe($deliveryDept->id)
        ->and($project->status)->toBe(ProjectStatusEnum::Active)
        ->and($project->health)->toBe(ProjectHealthEnum::OnTrack)
        ->and($project->start_date?->format('Y-m-d'))->toBe('2026-12-15');

    // Verify stages from template default_stages
    $stages = ProjectStage::query()
        ->where('project_id', $project->id)
        ->orderBy('sort_order')
        ->get();
    expect($stages)->toHaveCount(5)
        ->and($stages->pluck('name')->all())->toBe(['Backlog', 'To Do', 'In Progress', 'Review', 'Done']);

    // Verify 6 tasks with 1-level parent/child hierarchy, due_at = kickoff + relative_due_days, and assignee role mapping
    $tasks = Task::query()->where('project_id', $project->id)->get()->keyBy('title');
    expect($tasks)->toHaveCount(6);

    $kickoffParent = $tasks->get('Schedule and lead client kickoff call');
    $deckChild = $tasks->get('Prepare kickoff deck and milestone agenda');
    $attendeesChild = $tasks->get('Confirm client stakeholder attendees');

    expect($kickoffParent)->not->toBeNull()
        ->and($kickoffParent->parent_task_id)->toBeNull()
        ->and($kickoffParent->assignee_id)->toBe($pm->id)
        ->and($kickoffParent->due_at?->toDateString())->toBe(CarbonImmutable::parse('2026-12-15')->addDays(3)->toDateString())
        ->and($deckChild->parent_task_id)->toBe($kickoffParent->id)
        ->and($deckChild->assignee_id)->toBe($pm->id)
        ->and($deckChild->due_at?->toDateString())->toBe(CarbonImmutable::parse('2026-12-15')->addDays(2)->toDateString())
        ->and($attendeesChild->parent_task_id)->toBe($kickoffParent->id)
        ->and($attendeesChild->assignee_id)->toBe($am->id)
        ->and($attendeesChild->due_at?->toDateString())->toBe(CarbonImmutable::parse('2026-12-15')->addDays(2)->toDateString());

    // Verify deal_project pivot row
    expect(DB::table('deal_project')->where('deal_id', $deal->id)->where('project_id', $project->id)->exists())->toBeTrue();

    // Verify collaborator grants: PM Viewer on Company & Deal; AM Editor on Project
    $companyCollab = EntityCollaborator::query()
        ->where('collaboratable_type', 'company')
        ->where('collaboratable_id', $company->id)
        ->where('user_id', $pm->id)
        ->first();
    $dealCollab = EntityCollaborator::query()
        ->where('collaboratable_type', 'deal')
        ->where('collaboratable_id', $deal->id)
        ->where('user_id', $pm->id)
        ->first();
    $projectAmCollab = EntityCollaborator::query()
        ->where('collaboratable_type', 'project')
        ->where('collaboratable_id', $project->id)
        ->where('user_id', $am->id)
        ->first();

    expect($companyCollab?->access_level)->toBe(CollaboratorAccessLevel::Viewer)
        ->and($companyCollab?->role_label)->toBe(CollaboratorRoleLabel::ProjectManager)
        ->and($dealCollab?->access_level)->toBe(CollaboratorAccessLevel::Viewer)
        ->and($dealCollab?->role_label)->toBe(CollaboratorRoleLabel::ProjectManager)
        ->and($projectAmCollab?->access_level)->toBe(CollaboratorAccessLevel::Editor)
        ->and($projectAmCollab?->role_label)->toBe(CollaboratorRoleLabel::AccountManager);

    // Verify ActivityTypeEnum::ProjectProvisioned linked to deal, company, project
    $provisionedActivity = Activity::query()
        ->where('source_type', 'project')
        ->where('source_id', $project->id)
        ->where('activity_type', ActivityTypeEnum::ProjectProvisioned->value)
        ->first();
    expect($provisionedActivity)->not->toBeNull()
        ->and($provisionedActivity->actor_id)->toBe($opsMgr->id);

    $provisionedLinks = ActivityLink::query()
        ->where('activity_id', $provisionedActivity->id)
        ->get()
        ->map(static fn (ActivityLink $link): string => "{$link->linkable_type}:{$link->linkable_id}")
        ->all();
    expect($provisionedLinks)->toEqualCanonicalizing([
        "deal:{$deal->id}",
        "company:{$company->id}",
        "project:{$project->id}",
    ]);

    // Verify resulting Policy visibility: PM can view Company/Deal (Viewer, cannot update) & manage Project; cross-silo user denied
    $crossSiloPm = $createProvisioningUser($deliveryDept, 'EMP-PR-PM2', 'pm2.pr@acme.internal', RoleEnum::ProjectManager);
    expect(Gate::forUser($pm)->allows('view', $company->fresh()))->toBeTrue()
        ->and(Gate::forUser($pm)->allows('update', $company->fresh()))->toBeFalse()
        ->and(Gate::forUser($pm)->allows('view', $deal->fresh()))->toBeTrue()
        ->and(Gate::forUser($pm)->allows('update', $deal->fresh()))->toBeFalse()
        ->and(Gate::forUser($pm)->allows('view', $project))->toBeTrue()
        ->and(Gate::forUser($pm)->allows('update', $project))->toBeTrue()
        ->and(Gate::forUser($am)->allows('view', $project))->toBeTrue()
        ->and(Gate::forUser($crossSiloPm)->allows('view', $project))->toBeFalse();

    // 5. Strict Idempotency: re-dispatching TrackedJob, re-running handle(), and calling ProvisionProjectFromHandoffAction repeatedly yield 1 Project
    Queue::fake();
    $reDispatchedRun = ProvisionProjectFromHandoffJob::dispatchTracked(
        constructorArgs: ['handoffId' => $handoffId],
        subjectType: 'deal_handoff',
        subjectId: $handoffId,
        triggeredById: (string) $opsMgr->id,
        idempotencyKey: "handoff-provision:{$handoffId}",
        payloadSummary: ['deal_id' => (string) $deal->id],
        queue: 'default',
    );
    expect($reDispatchedRun->id)->toBe($jobRun->id);
    Queue::assertNothingPushed();

    $duplicateJob = new ProvisionProjectFromHandoffJob($handoffId, (string) $jobRun->id);
    $duplicateJob->handle();

    $provisionAction = app(ProvisionProjectFromHandoffAction::class);
    $returnedFirst = $provisionAction->execute($handoffId);
    $returnedSecond = $provisionAction->execute($handoffId);

    expect($returnedFirst->id)->toBe($project->id)
        ->and($returnedSecond->id)->toBe($project->id)
        ->and(Project::query()->where('deal_handoff_id', $handoffId)->count())->toBe(1)
        ->and(ProjectStage::query()->where('project_id', $project->id)->count())->toBe(5)
        ->and(Task::query()->where('project_id', $project->id)->count())->toBe(6)
        ->and(DB::table('deal_project')->where('deal_id', $deal->id)->count())->toBe(1);
});

it('suppresses HandoffStatusChanged broadcasts and ProvisionProjectFromHandoffJob dispatch on rollback, and isolates broadcast exceptions', function () use ($createProvisioningUser): void {
    $dept = Department::query()->create(['name' => 'Ops', 'slug' => 'prov-rollback']);

    $repA = $createProvisioningUser($dept, 'EMP-RB-REP', 'rep.rb@acme.internal', RoleEnum::Sales);
    $opsMgr = $createProvisioningUser($dept, 'EMP-RB-OPS', 'ops.rb@acme.internal', RoleEnum::OperationsManager);
    $pm = $createProvisioningUser($dept, 'EMP-RB-PM', 'pm.rb@acme.internal', RoleEnum::ProjectManager);
    $suspendedAm = $createProvisioningUser($dept, 'EMP-RB-SAM', 'sam.rb@acme.internal', RoleEnum::AccountManager, null, UserStatusEnum::Suspended);

    $pipeline = Pipeline::query()->where('slug', 'new-business')->firstOrFail();
    $wonStage = $pipeline->stages()->where('stage_type', PipelineStageTypeEnum::Won->value)->firstOrFail();
    $template = ProjectTemplate::query()->where('name', 'Standard Client Onboarding')->firstOrFail();

    $company = Company::query()->create(['name' => 'Rollback Co', 'owner_id' => $repA->id]);
    $deal = Deal::query()->create([
        'company_id' => $company->id,
        'pipeline_id' => $pipeline->id,
        'stage_id' => $wonStage->id,
        'owner_id' => $repA->id,
        'title' => 'Rollback Deal',
        'stage_entered_at' => now(),
        'won_at' => now(),
    ]);

    $handoff = DealHandoff::query()->create([
        'deal_id' => $deal->id,
        'company_id' => $company->id,
        'submitted_by_id' => $repA->id,
        'status' => DealHandoffStatusEnum::PendingReview,
        'scope_notes_json' => [
            'type' => 'doc',
            'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Scope']]]],
        ],
        'submitted_at' => now(),
    ]);

    // 1. Rolled back approval must suppress broadcasts and TrackedJob dispatch
    Event::fake([HandoffStatusChanged::class]);

    try {
        DB::transaction(function () use ($opsMgr, $handoff, $template, $pm): void {
            app(ApproveDealHandoffAction::class)->execute(
                $opsMgr,
                $handoff,
                new ApproveHandoffData(
                    project_template_id: (string) $template->id,
                    assigned_pm_id: (string) $pm->id,
                ),
            );

            Event::assertNotDispatched(HandoffStatusChanged::class);

            throw new RuntimeException('Abort approval transaction');
        });
    } catch (RuntimeException) {
        // Expected rollback
    }

    Event::assertNotDispatched(HandoffStatusChanged::class);
    expect($handoff->fresh()->status)->toBe(DealHandoffStatusEnum::PendingReview)
        ->and(AsyncJobRun::query()->where('subject_id', $handoff->id)->exists())->toBeFalse()
        ->and(Project::query()->where('deal_handoff_id', $handoff->id)->exists())->toBeFalse();

    // 2. Broadcast exception after commit must NEVER fail approval or provisioning,
    //    and suspended stakeholders are excluded from broadcast recipients
    $handoff->update(['assigned_am_id' => $suspendedAm->id]);
    app(RequestHandoffChangesAction::class)->execute(
        $opsMgr,
        $handoff->fresh(),
        new ReviewHandoffData('Check AM status.'),
    );

    $suspendedBroadcasts = Event::dispatched(HandoffStatusChanged::class)
        ->filter(static fn (array $e): bool => $e[0]->recipientUserId === $suspendedAm->id);
    expect($suspendedBroadcasts)->toBeEmpty();

    app(ResubmitDealHandoffAction::class)->execute(
        $repA,
        $handoff->fresh(),
        new SubmitHandoffData(
            scope_notes_json: [
                'type' => 'doc',
                'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Resubmitted scope']]]],
            ],
            handoff_checklist: [],
            assigned_am_id: null,
        ),
    );

    Event::listen(HandoffStatusChanged::class, static function (): void {
        throw new RuntimeException('Simulated Reverb outage');
    });

    try {
        $result = app(ApproveDealHandoffAction::class)->execute(
            $opsMgr,
            $handoff->fresh(),
            new ApproveHandoffData(
                project_template_id: (string) $template->id,
                assigned_pm_id: (string) $pm->id,
            ),
        );

        expect($result->fresh()->status)->toBe(DealHandoffStatusEnum::Provisioned)
            ->and(Project::query()->where('deal_handoff_id', $handoff->id)->count())->toBe(1);
    } finally {
        Event::forget(HandoffStatusChanged::class);
    }
});

it('handles reject broadcasts, role-hint null fallback, default project name, and second-PDO concurrent duplicate/idempotency safety', function () use ($createProvisioningUser): void {
    $dept = Department::query()->create(['name' => 'Delivery', 'slug' => 'prov-edge']);

    $repA = $createProvisioningUser($dept, 'EMP-ED-REP', 'rep.ed@acme.internal', RoleEnum::Sales);
    $opsMgr = $createProvisioningUser($dept, 'EMP-ED-OPS', 'ops.ed@acme.internal', RoleEnum::OperationsManager);
    $pm = $createProvisioningUser($dept, 'EMP-ED-PM', 'pm.ed@acme.internal', RoleEnum::ProjectManager);

    $pipeline = Pipeline::query()->where('slug', 'new-business')->firstOrFail();
    $wonStage = $pipeline->stages()->where('stage_type', PipelineStageTypeEnum::Won->value)->firstOrFail();

    $company = Company::query()->create(['name' => 'Edge Co', 'owner_id' => $repA->id]);
    $deal = Deal::query()->create([
        'company_id' => $company->id,
        'pipeline_id' => $pipeline->id,
        'stage_id' => $wonStage->id,
        'owner_id' => $repA->id,
        'title' => 'Edge Default Named Deal',
        'stage_entered_at' => now(),
        'won_at' => now(),
    ]);

    // 1. Reject broadcast verifies ID-only payload to submitter
    $rejectedHandoff = DealHandoff::query()->create([
        'deal_id' => $deal->id,
        'company_id' => $company->id,
        'submitted_by_id' => $repA->id,
        'status' => DealHandoffStatusEnum::PendingReview,
        'scope_notes_json' => [
            'type' => 'doc',
            'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Reject check']]]],
        ],
        'submitted_at' => now(),
    ]);

    Event::fake([HandoffStatusChanged::class]);
    app(RejectDealHandoffAction::class)->execute(
        $opsMgr,
        $rejectedHandoff,
        new ReviewHandoffData('Incomplete scope.'),
    );

    $rejectEvents = Event::dispatched(HandoffStatusChanged::class);
    expect($rejectEvents)->toHaveCount(1)
        ->and($rejectEvents[0][0]->recipientUserId)->toBe($repA->id)
        ->and($rejectEvents[0][0]->broadcastWith())->toBe([
            'handoff_id' => $rejectedHandoff->id,
            'status' => DealHandoffStatusEnum::Rejected->value,
            'deal_id' => $deal->id,
        ]);

    // 2. Template with Support/It/Am hints when AM is null + no project_name + null target_kickoff_date
    $customTemplate = ProjectTemplate::query()->create([
        'name' => 'Support & IT Template',
        'default_stages' => [
            ['key' => 'todo', 'name' => 'To Do', 'category' => 'todo', 'sort_order' => 1, 'wip_limit' => null],
        ],
        'is_active' => true,
        'created_by_id' => $opsMgr->id,
    ]);
    ProjectTemplateTask::query()->create([
        'project_template_id' => $customTemplate->id,
        'stage_key' => 'todo',
        'title' => 'Support intake check',
        'assignee_role_hint' => TemplateAssigneeRoleHintEnum::Support,
        'relative_due_days' => 2,
        'sort_order' => 1,
    ]);
    ProjectTemplateTask::query()->create([
        'project_template_id' => $customTemplate->id,
        'stage_key' => 'todo',
        'title' => 'IT provisioning',
        'assignee_role_hint' => TemplateAssigneeRoleHintEnum::It,
        'relative_due_days' => null,
        'sort_order' => 2,
    ]);
    ProjectTemplateTask::query()->create([
        'project_template_id' => $customTemplate->id,
        'stage_key' => 'todo',
        'title' => 'AM welcome note',
        'assignee_role_hint' => TemplateAssigneeRoleHintEnum::Am,
        'relative_due_days' => 1,
        'sort_order' => 3,
    ]);

    $handoff2 = app(SubmitDealHandoffAction::class)->execute(
        $repA,
        $deal,
        new SubmitHandoffData(
            scope_notes_json: [
                'type' => 'doc',
                'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Default kickoff & name']]]],
            ],
        ),
    );

    $approvedHandoff2 = app(ApproveDealHandoffAction::class)->execute(
        $opsMgr,
        $handoff2,
        new ApproveHandoffData(
            project_template_id: (string) $customTemplate->id,
            assigned_pm_id: (string) $pm->id,
        ),
    );

    $provisionedProject2 = Project::query()->where('deal_handoff_id', $approvedHandoff2->id)->firstOrFail();
    expect($provisionedProject2->name)->toBe('Edge Default Named Deal')
        ->and($provisionedProject2->account_manager_id)->toBeNull()
        ->and($provisionedProject2->start_date)->toBeNull();

    $tasks2 = Task::query()->where('project_id', $provisionedProject2->id)->get()->keyBy('title');
    expect($tasks2->get('Support intake check')->assignee_id)->toBeNull()
        ->and($tasks2->get('IT provisioning')->assignee_id)->toBeNull()
        ->and($tasks2->get('IT provisioning')->due_at)->toBeNull()
        ->and($tasks2->get('AM welcome note')->assignee_id)->toBeNull();

    // 3. Second PDO concurrent insert of active handoff -> SubmitDealHandoffAction returns 422 ValidationException cleanly,
    //    and pre-existing Project row for deal_handoff_id -> ProvisionProjectFromHandoffAction returns it idempotently.
    $pgConfig = config('database.connections.pgsql');
    $secondPdo = new PDO(
        sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            $pgConfig['host'],
            $pgConfig['port'],
            $pgConfig['database'],
        ),
        $pgConfig['username'],
        $pgConfig['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );

    $dealRace = Deal::query()->create([
        'company_id' => $company->id,
        'pipeline_id' => $pipeline->id,
        'stage_id' => $wonStage->id,
        'owner_id' => $repA->id,
        'title' => 'Concurrent Race Deal',
        'stage_entered_at' => now(),
        'won_at' => now(),
    ]);

    $racedHandoffId = (string) Str::uuid7();
    $secondPdo->prepare(
        "INSERT INTO deal_handoffs (id, deal_id, company_id, submitted_by_id, status, scope_notes_json, handoff_checklist, submitted_at, created_at, updated_at)
         VALUES (:id, :deal_id, :company_id, :submitted_by_id, 'pending_review', '{}', '[]', NOW(), NOW(), NOW())"
    )->execute([
        ':id' => $racedHandoffId,
        ':deal_id' => $dealRace->id,
        ':company_id' => $company->id,
        ':submitted_by_id' => $repA->id,
    ]);

    expect(static fn () => app(SubmitDealHandoffAction::class)->execute(
        $repA,
        $dealRace,
        new SubmitHandoffData(
            scope_notes_json: [
                'type' => 'doc',
                'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Race submission']]]],
            ],
        ),
    ))->toThrow(ValidationException::class);

    // Pre-inserted Project for an approved handoff reconciles idempotently
    $handoffRaceApprove = DealHandoff::query()->findOrFail($racedHandoffId);
    $handoffRaceApprove->update([
        'status' => DealHandoffStatusEnum::Approved,
        'assigned_pm_id' => $pm->id,
        'project_template_id' => $customTemplate->id,
        'reviewed_by_id' => $opsMgr->id,
        'reviewed_at' => now(),
    ]);

    $existingProjectId = (string) Str::uuid7();
    $secondPdo->prepare(
        "INSERT INTO projects (id, code, company_id, primary_deal_id, deal_handoff_id, owner_id, department_id, name, status, health, custom_fields, created_at, updated_at)
         VALUES (:id, :code, :company_id, :deal_id, :handoff_id, :owner_id, :department_id, :name, 'active', 'on_track', '{}', NOW(), NOW())"
    )->execute([
        ':id' => $existingProjectId,
        ':code' => 'PRJ-2026-99999',
        ':company_id' => $company->id,
        ':deal_id' => $dealRace->id,
        ':handoff_id' => $racedHandoffId,
        ':owner_id' => $pm->id,
        ':department_id' => $dept->id,
        ':name' => 'Pre-Provisioned Project',
    ]);

    $reconciled = app(ProvisionProjectFromHandoffAction::class)->execute($racedHandoffId);
    expect($reconciled->id)->toBe($existingProjectId)
        ->and($handoffRaceApprove->fresh()->status)->toBe(DealHandoffStatusEnum::Provisioned)
        ->and(Project::query()->where('deal_handoff_id', $racedHandoffId)->count())->toBe(1);
});
