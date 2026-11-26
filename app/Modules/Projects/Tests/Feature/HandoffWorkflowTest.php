<?php

use App\Modules\Core\Rbac\Enums\RoleEnum;
use App\Modules\Core\Users\Actions\RebuildUserHierarchyClosureAction;
use App\Modules\Core\Users\Enums\UserStatusEnum;
use App\Modules\Core\Users\Models\Department;
use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Companies\Models\Company;
use App\Modules\Crm\Deals\Models\Deal;
use App\Modules\Crm\Pipelines\Enums\PipelineStageTypeEnum;
use App\Modules\Crm\Pipelines\Models\Pipeline;
use App\Modules\Crm\Pipelines\Models\PipelineStage;
use App\Modules\Crm\Timeline\Enums\ActivityTypeEnum;
use App\Modules\Crm\Timeline\Models\Activity;
use App\Modules\Crm\Timeline\Models\ActivityLink;
use App\Modules\Projects\Handoffs\Enums\DealHandoffStatusEnum;
use App\Modules\Projects\Handoffs\Jobs\ProvisionProjectFromHandoffJob;
use App\Modules\Projects\Handoffs\Models\DealHandoff;
use App\Modules\Projects\Handoffs\Support\DealHandoffStateMachine;
use App\Modules\Projects\Projects\Enums\ProjectStageCategoryEnum;
use App\Modules\Projects\Templates\Models\ProjectTemplate;
use Database\Seeders\CrmDefaultsSeeder;
use Database\Seeders\ProjectDefaultsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

function createHandoffWorkflowUser(
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

function validScopeNotes(string $text = 'Contracted Phase 1 delivery scope.'): array
{
    return [
        'type' => 'doc',
        'content' => [
            [
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => $text],
                ],
            ],
        ],
    ];
}

function createDealForHandoff(
    User $owner,
    Pipeline $pipeline,
    PipelineStage $stage,
    string $title = 'Northwind Enterprise Rollout',
    ?User $accountManager = null,
): array {
    $company = Company::query()->create([
        'name' => "{$title} Client",
        'owner_id' => $owner->id,
        'account_manager_id' => $accountManager?->id,
    ]);

    $deal = Deal::query()->create([
        'company_id' => $company->id,
        'pipeline_id' => $pipeline->id,
        'stage_id' => $stage->id,
        'owner_id' => $owner->id,
        'account_manager_id' => $accountManager?->id,
        'title' => $title,
        'amount_cents' => 1500000,
        'currency' => 'USD',
        'stage_entered_at' => now(),
        'won_at' => $stage->stage_type === PipelineStageTypeEnum::Won ? now() : null,
    ]);

    return [$company, $deal];
}

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
});

it('enforces all legal and illegal DealHandoffStateMachine transitions', function (): void {
    $allowed = [
        DealHandoffStatusEnum::PendingReview->value => [
            DealHandoffStatusEnum::ChangesRequested->value,
            DealHandoffStatusEnum::Approved->value,
            DealHandoffStatusEnum::Rejected->value,
        ],
        DealHandoffStatusEnum::ChangesRequested->value => [
            DealHandoffStatusEnum::PendingReview->value,
        ],
        DealHandoffStatusEnum::Approved->value => [
            DealHandoffStatusEnum::Provisioned->value,
        ],
        DealHandoffStatusEnum::Provisioned->value => [],
        DealHandoffStatusEnum::Rejected->value => [],
    ];

    foreach (DealHandoffStatusEnum::cases() as $from) {
        foreach (DealHandoffStatusEnum::cases() as $to) {
            $shouldAllow = in_array($to->value, $allowed[$from->value], true);

            expect(DealHandoffStateMachine::canTransition($from, $to))->toBe($shouldAllow);

            if ($shouldAllow) {
                DealHandoffStateMachine::assertCanTransition($from, $to);
            } else {
                expect(static fn () => DealHandoffStateMachine::assertCanTransition($from, $to))
                    ->toThrow(ValidationException::class);
            }
        }
    }
});

it('validates deal stage_type === won, XSS sanitization, and active non-duplicate submission rules', function (): void {
    $dept = Department::query()->create(['name' => 'Commercial', 'slug' => 'handoff-wf-comm']);

    $repA = createHandoffWorkflowUser($dept, 'EMP-WF-REPA', 'rep.a@acme.internal', RoleEnum::Sales);
    $am = createHandoffWorkflowUser($dept, 'EMP-WF-AM', 'am@acme.internal', RoleEnum::AccountManager);
    $suspendedAm = createHandoffWorkflowUser($dept, 'EMP-WF-SAM', 'sam@acme.internal', RoleEnum::AccountManager, null, UserStatusEnum::Suspended);

    $pipeline = Pipeline::query()->where('slug', 'new-business')->firstOrFail();
    $openStage = $pipeline->stages()->where('stage_type', PipelineStageTypeEnum::Open->value)->firstOrFail();
    $wonStage = $pipeline->stages()->where('stage_type', PipelineStageTypeEnum::Won->value)->firstOrFail();
    $lostStage = $pipeline->stages()->where('stage_type', PipelineStageTypeEnum::Lost->value)->firstOrFail();

    [, $openDeal] = createDealForHandoff($repA, $pipeline, $openStage, 'Open Deal');
    [, $lostDeal] = createDealForHandoff($repA, $pipeline, $lostStage, 'Lost Deal');
    [$companyWon, $wonDeal] = createDealForHandoff($repA, $pipeline, $wonStage, 'Won Deal');

    // Open & Lost deals cannot be handed off (422)
    $this->actingAs($repA)
        ->postJson("/api/internal/crm/deals/{$openDeal->id}/handoffs", [
            'scope_notes_json' => validScopeNotes(),
            'handoff_checklist' => [['label' => 'Signed SOW', 'checked' => true]],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['deal_id']);

    $this->actingAs($repA)
        ->postJson("/api/internal/crm/deals/{$lostDeal->id}/handoffs", [
            'scope_notes_json' => validScopeNotes(),
            'handoff_checklist' => [],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['deal_id']);

    // Empty / invalid TipTap document fails 422
    $this->actingAs($repA)
        ->postJson("/api/internal/crm/deals/{$wonDeal->id}/handoffs", [
            'scope_notes_json' => ['type' => 'doc', 'content' => []],
            'handoff_checklist' => [],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['scope_notes_json']);

    // Inactive AM rejected with 422
    $this->actingAs($repA)
        ->postJson("/api/internal/crm/deals/{$wonDeal->id}/handoffs", [
            'scope_notes_json' => validScopeNotes(),
            'handoff_checklist' => [],
            'assigned_am_id' => $suspendedAm->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['assigned_am_id']);

    // Valid won deal submission sanitizes XSS nodes & unsafe hrefs
    $dirtyNotes = [
        'type' => 'doc',
        'onclick' => 'alert(1)',
        'content' => [
            [
                'type' => 'script',
                'content' => [['type' => 'text', 'text' => 'evil()']],
            ],
            [
                'type' => 'paragraph',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'Clean scope',
                        'marks' => [
                            ['type' => 'link', 'attrs' => ['href' => 'https://docs.acme.internal/sow']],
                        ],
                    ],
                    [
                        'type' => 'text',
                        'text' => 'bad',
                        'marks' => [
                            ['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']],
                        ],
                    ],
                ],
            ],
        ],
    ];

    $response = $this->actingAs($repA)
        ->postJson("/api/internal/crm/deals/{$wonDeal->id}/handoffs", [
            'scope_notes_json' => $dirtyNotes,
            'handoff_checklist' => [
                ['label' => 'Signed MSA', 'checked' => true],
                ['label' => 'Billing contact confirmed', 'checked' => false],
            ],
            'target_kickoff_date' => '2026-12-15',
            'assigned_am_id' => $am->id,
        ])
        ->assertCreated();

    $handoffId = $response->json('id');
    expect($response->json('status'))->toBe(DealHandoffStatusEnum::PendingReview->value)
        ->and($response->json('target_kickoff_date'))->toBe('2026-12-15')
        ->and($response->json('assigned_am.id'))->toBe($am->id);

    $stored = DealHandoff::query()->findOrFail($handoffId);
    expect($stored->scope_notes_json)->not->toHaveKey('onclick')
        ->and(collect($stored->scope_notes_json['content'])->pluck('type')->all())->toBe(['paragraph'])
        ->and(json_encode($stored->scope_notes_json))->not->toContain('javascript:')
        ->and(json_encode($stored->scope_notes_json))->not->toContain('evil()');

    // HandoffSubmitted activity recorded on deal + company
    $activity = Activity::query()
        ->where('source_type', 'deal_handoff')
        ->where('source_id', $stored->id)
        ->where('activity_type', ActivityTypeEnum::HandoffSubmitted->value)
        ->first();
    expect($activity)->not->toBeNull();

    $linkedTargets = ActivityLink::query()
        ->where('activity_id', $activity->id)
        ->get()
        ->map(static fn (ActivityLink $link): string => "{$link->linkable_type}:{$link->linkable_id}")
        ->all();
    expect($linkedTargets)->toEqualCanonicalizing([
        "deal:{$wonDeal->id}",
        "company:{$companyWon->id}",
    ]);

    // Duplicate active submission rejected with 422
    $this->actingAs($repA)
        ->postJson("/api/internal/crm/deals/{$wonDeal->id}/handoffs", [
            'scope_notes_json' => validScopeNotes('Second attempt'),
            'handoff_checklist' => [],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['deal_id']);
});

it('executes workflow transitions, validates approval inputs, enforces illegal transition 422s, and allows re-submission after rejection', function (): void {
    $dept = Department::query()->create(['name' => 'Delivery', 'slug' => 'handoff-wf-del']);

    $repA = createHandoffWorkflowUser($dept, 'EMP-TR-REPA', 'rep.tr@acme.internal', RoleEnum::Sales);
    $opsMgr = createHandoffWorkflowUser($dept, 'EMP-TR-OPS', 'ops.tr@acme.internal', RoleEnum::OperationsManager);
    $pm = createHandoffWorkflowUser($dept, 'EMP-TR-PM', 'pm.tr@acme.internal', RoleEnum::ProjectManager);
    $am = createHandoffWorkflowUser($dept, 'EMP-TR-AM', 'am.tr@acme.internal', RoleEnum::AccountManager);
    $inactivePm = createHandoffWorkflowUser($dept, 'EMP-TR-IPM', 'ipm.tr@acme.internal', RoleEnum::ProjectManager, null, UserStatusEnum::Suspended);
    $trashedPm = createHandoffWorkflowUser($dept, 'EMP-TR-TPM', 'tpm.tr@acme.internal', RoleEnum::ProjectManager);
    $trashedPm->delete();

    $pipeline = Pipeline::query()->where('slug', 'new-business')->firstOrFail();
    $wonStage = $pipeline->stages()->where('stage_type', PipelineStageTypeEnum::Won->value)->firstOrFail();
    [$company, $deal] = createDealForHandoff($repA, $pipeline, $wonStage, 'Acme Rollout');

    $activeTemplate = ProjectTemplate::query()->where('name', 'Standard Client Onboarding')->firstOrFail();
    $inactiveTemplate = ProjectTemplate::query()->create([
        'name' => 'Archived Template',
        'default_stages' => [
            ['key' => 'todo', 'name' => 'To Do', 'category' => ProjectStageCategoryEnum::Todo->value, 'sort_order' => 1, 'wip_limit' => null],
        ],
        'is_active' => false,
        'created_by_id' => $opsMgr->id,
    ]);

    // 1. Submit
    $handoffId = $this->actingAs($repA)
        ->postJson("/api/internal/crm/deals/{$deal->id}/handoffs", [
            'scope_notes_json' => validScopeNotes('Initial scope'),
            'handoff_checklist' => [['label' => 'SOW signed', 'checked' => true]],
            'target_kickoff_date' => '2026-12-20',
        ])
        ->assertCreated()
        ->json('id');

    // Cannot resubmit while already pending_review (422)
    $this->actingAs($repA)
        ->postJson("/api/internal/handoffs/{$handoffId}/resubmit", [
            'scope_notes_json' => validScopeNotes('Premature resubmit'),
            'handoff_checklist' => [],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);

    // Request changes requires non-empty review_feedback (422)
    $this->actingAs($opsMgr)
        ->postJson("/api/internal/handoffs/{$handoffId}/request-changes", [
            'review_feedback' => '   ',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['review_feedback']);

    // 2. Request changes (pending_review -> changes_requested)
    $this->actingAs($opsMgr)
        ->postJson("/api/internal/handoffs/{$handoffId}/request-changes", [
            'review_feedback' => 'Please attach signed security addendum.',
        ])
        ->assertOk()
        ->assertJsonPath('status', DealHandoffStatusEnum::ChangesRequested->value)
        ->assertJsonPath('review_feedback', 'Please attach signed security addendum.');

    // Cannot approve or reject while in changes_requested (422)
    $this->actingAs($opsMgr)
        ->postJson("/api/internal/handoffs/{$handoffId}/approve", [
            'project_template_id' => $activeTemplate->id,
            'assigned_pm_id' => $pm->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);

    $this->actingAs($opsMgr)
        ->postJson("/api/internal/handoffs/{$handoffId}/reject", [
            'review_feedback' => 'Cannot reject directly from changes_requested.',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);

    // 3. Resubmit (changes_requested -> pending_review)
    $this->actingAs($repA)
        ->postJson("/api/internal/handoffs/{$handoffId}/resubmit", [
            'scope_notes_json' => validScopeNotes('Updated scope with security addendum'),
            'handoff_checklist' => [['label' => 'Security addendum', 'checked' => true]],
            'target_kickoff_date' => '2026-12-22',
            'assigned_am_id' => $am->id,
        ])
        ->assertOk()
        ->assertJsonPath('status', DealHandoffStatusEnum::PendingReview->value)
        ->assertJsonPath('target_kickoff_date', '2026-12-22');

    // 4. Reject requires non-empty feedback and moves to terminal rejected
    $this->actingAs($opsMgr)
        ->postJson("/api/internal/handoffs/{$handoffId}/reject", [
            'review_feedback' => '',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['review_feedback']);

    $this->actingAs($opsMgr)
        ->postJson("/api/internal/handoffs/{$handoffId}/reject", [
            'review_feedback' => 'Out of delivery capacity this quarter.',
        ])
        ->assertOk()
        ->assertJsonPath('status', DealHandoffStatusEnum::Rejected->value);

    // Rejected handoff cannot be resubmitted, approved, or changed (422)
    $this->actingAs($repA)
        ->postJson("/api/internal/handoffs/{$handoffId}/resubmit", [
            'scope_notes_json' => validScopeNotes('Retry'),
            'handoff_checklist' => [],
        ])
        ->assertUnprocessable();

    $this->actingAs($opsMgr)
        ->postJson("/api/internal/handoffs/{$handoffId}/approve", [
            'project_template_id' => $activeTemplate->id,
            'assigned_pm_id' => $pm->id,
        ])
        ->assertUnprocessable();

    // 5. New handoff submission IS allowed on deal after previous handoff was rejected
    $handoff2Id = $this->actingAs($repA)
        ->postJson("/api/internal/crm/deals/{$deal->id}/handoffs", [
            'scope_notes_json' => validScopeNotes('Fresh Q1 scope'),
            'handoff_checklist' => [['label' => 'Q1 kickoff ready', 'checked' => true]],
            'target_kickoff_date' => '2027-01-05',
        ])
        ->assertCreated()
        ->json('id');

    // Approve validations: inactive template, inactive PM, trashed PM, PM lacking projects.update -> 422
    $this->actingAs($opsMgr)
        ->postJson("/api/internal/handoffs/{$handoff2Id}/approve", [
            'project_template_id' => $inactiveTemplate->id,
            'assigned_pm_id' => $pm->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['project_template_id']);

    $this->actingAs($opsMgr)
        ->postJson("/api/internal/handoffs/{$handoff2Id}/approve", [
            'project_template_id' => $activeTemplate->id,
            'assigned_pm_id' => $inactivePm->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['assigned_pm_id']);

    $this->actingAs($opsMgr)
        ->postJson("/api/internal/handoffs/{$handoff2Id}/approve", [
            'project_template_id' => $activeTemplate->id,
            'assigned_pm_id' => $trashedPm->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['assigned_pm_id']);

    $this->actingAs($opsMgr)
        ->postJson("/api/internal/handoffs/{$handoff2Id}/approve", [
            'project_template_id' => $activeTemplate->id,
            'assigned_pm_id' => $repA->id, // Sales lacks projects.update
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['assigned_pm_id']);

    // Valid approval sets status = approved, queues ProvisionProjectFromHandoffJob, and records HandoffApproved activity on deal + company
    Queue::fake();

    $this->actingAs($opsMgr)
        ->postJson("/api/internal/handoffs/{$handoff2Id}/approve", [
            'project_template_id' => $activeTemplate->id,
            'assigned_pm_id' => $pm->id,
            'assigned_am_id' => $am->id,
            'project_name' => 'Acme Q1 Onboarding',
            'review_feedback' => 'Approved for Q1 delivery.',
        ])
        ->assertOk()
        ->assertJsonPath('status', DealHandoffStatusEnum::Approved->value)
        ->assertJsonPath('assigned_pm.id', $pm->id)
        ->assertJsonPath('assigned_am.id', $am->id)
        ->assertJsonPath('template.id', $activeTemplate->id);

    Queue::assertPushed(
        ProvisionProjectFromHandoffJob::class,
        static fn (ProvisionProjectFromHandoffJob $job): bool => $job->handoffId === $handoff2Id,
    );

    $approvedActivity = Activity::query()
        ->where('source_type', 'deal_handoff')
        ->where('source_id', $handoff2Id)
        ->where('activity_type', ActivityTypeEnum::HandoffApproved->value)
        ->first();
    expect($approvedActivity)->not->toBeNull();

    $approvedLinks = ActivityLink::query()
        ->where('activity_id', $approvedActivity->id)
        ->get()
        ->map(static fn (ActivityLink $link): string => "{$link->linkable_type}:{$link->linkable_id}")
        ->all();
    expect($approvedLinks)->toEqualCanonicalizing([
        "deal:{$deal->id}",
        "company:{$company->id}",
    ]);

    // Double approve fails 422
    $this->actingAs($opsMgr)
        ->postJson("/api/internal/handoffs/{$handoff2Id}/approve", [
            'project_template_id' => $activeTemplate->id,
            'assigned_pm_id' => $pm->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);
});

it('enforces RBAC permissions, cross-silo IDOR isolation, Inertia pages, templates endpoint, and unreadCounts.handoffs', function (): void {
    $dept = Department::query()->create(['name' => 'Revenue', 'slug' => 'handoff-wf-rbac']);

    $superadmin = createHandoffWorkflowUser($dept, 'EMP-RB-SA', 'sa.rb@acme.internal', RoleEnum::Superadmin);
    $admin = createHandoffWorkflowUser($dept, 'EMP-RB-ADM', 'adm.rb@acme.internal', RoleEnum::Admin);
    $directorA = createHandoffWorkflowUser($dept, 'EMP-RB-DIRA', 'dir.a.rb@acme.internal', RoleEnum::Director);
    $repA = createHandoffWorkflowUser($dept, 'EMP-RB-REPA', 'rep.a.rb@acme.internal', RoleEnum::Sales, $directorA);
    $repB = createHandoffWorkflowUser($dept, 'EMP-RB-REPB', 'rep.b.rb@acme.internal', RoleEnum::Sales);
    $opsMgr = createHandoffWorkflowUser($dept, 'EMP-RB-OPS', 'ops.rb@acme.internal', RoleEnum::OperationsManager);
    $pmAssigned = createHandoffWorkflowUser($dept, 'EMP-RB-PM1', 'pm1.rb@acme.internal', RoleEnum::ProjectManager);
    $pmUnassigned = createHandoffWorkflowUser($dept, 'EMP-RB-PM2', 'pm2.rb@acme.internal', RoleEnum::ProjectManager);
    $am = createHandoffWorkflowUser($dept, 'EMP-RB-AM', 'am.rb@acme.internal', RoleEnum::AccountManager);
    $support = createHandoffWorkflowUser($dept, 'EMP-RB-SUP', 'sup.rb@acme.internal', RoleEnum::Support);
    $it = createHandoffWorkflowUser($dept, 'EMP-RB-IT', 'it.rb@acme.internal', RoleEnum::It);
    $zeroRole = createHandoffWorkflowUser($dept, 'EMP-RB-ZR', 'zero.rb@acme.internal', null);

    $pipeline = Pipeline::query()->where('slug', 'new-business')->firstOrFail();
    $wonStage = $pipeline->stages()->where('stage_type', PipelineStageTypeEnum::Won->value)->firstOrFail();
    [$companyA, $dealA] = createDealForHandoff($repA, $pipeline, $wonStage, 'Silo A Secret Deal');
    [$companyB, $dealB] = createDealForHandoff($repB, $pipeline, $wonStage, 'Silo B Deal');

    $template = ProjectTemplate::query()->where('name', 'Standard Client Onboarding')->firstOrFail();
    ProjectTemplate::query()->create([
        'name' => 'Inactive Template',
        'default_stages' => [
            ['key' => 'todo', 'name' => 'To Do', 'category' => ProjectStageCategoryEnum::Todo->value, 'sort_order' => 1, 'wip_limit' => null],
        ],
        'is_active' => false,
        'created_by_id' => $opsMgr->id,
    ]);

    $handoffA = DealHandoff::query()->create([
        'deal_id' => $dealA->id,
        'company_id' => $companyA->id,
        'submitted_by_id' => $repA->id,
        'assigned_pm_id' => $pmAssigned->id,
        'assigned_am_id' => $am->id,
        'project_template_id' => $template->id,
        'status' => DealHandoffStatusEnum::PendingReview,
        'scope_notes_json' => validScopeNotes('Silo A confidential scope'),
        'submitted_at' => now(),
    ]);

    $handoffB = DealHandoff::query()->create([
        'deal_id' => $dealB->id,
        'company_id' => $companyB->id,
        'submitted_by_id' => $repB->id,
        'status' => DealHandoffStatusEnum::ChangesRequested,
        'scope_notes_json' => validScopeNotes('Silo B scope'),
        'submitted_at' => now()->subMinute(),
    ]);

    // Unauthenticated rejected
    $this->get('/app/projects/handoffs')->assertRedirect('/login');
    $this->get("/app/projects/handoffs/{$handoffA->id}")->assertRedirect('/login');
    $this->postJson("/api/internal/crm/deals/{$dealA->id}/handoffs", [])->assertUnauthorized();
    $this->getJson('/api/internal/project-templates')->assertUnauthorized();

    // Support, It, ZeroRole denied everywhere
    foreach ([$support, $it, $zeroRole] as $denied) {
        $this->actingAs($denied)->get('/app/projects/handoffs')->assertForbidden();
        $this->actingAs($denied)->get("/app/projects/handoffs/{$handoffA->id}")->assertForbidden();
        $this->actingAs($denied)->getJson('/api/internal/project-templates')->assertForbidden();
    }

    // Cross-silo submit/resubmit/view IDOR checks
    $this->actingAs($repB)
        ->postJson("/api/internal/crm/deals/{$dealA->id}/handoffs", [
            'scope_notes_json' => validScopeNotes(),
            'handoff_checklist' => [],
        ])
        ->assertForbidden();

    $this->actingAs($opsMgr)
        ->postJson("/api/internal/crm/deals/{$dealA->id}/handoffs", [
            'scope_notes_json' => validScopeNotes(),
            'handoff_checklist' => [],
        ])
        ->assertForbidden();

    // Rep B cannot view Handoff A; Unassigned PM cannot view Handoff A
    $this->actingAs($repB)->get("/app/projects/handoffs/{$handoffA->id}")->assertForbidden();
    $this->actingAs($pmUnassigned)->get("/app/projects/handoffs/{$handoffA->id}")->assertForbidden();

    // Rep A sees only Handoff A in queue, unreadCounts.handoffs === 0 (no handoffs.review)
    $this->actingAs($repA)
        ->get('/app/projects/handoffs')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/pages/HandoffIntakePage')
            ->where('unreadCounts.handoffs', 0)
            ->has('handoffs', 1)
            ->where('handoffs.0.id', $handoffA->id)
        );

    // Assigned PM can view Handoff A detail and request changes; Unassigned PM cannot; Rep A cannot
    $this->actingAs($pmAssigned)
        ->get("/app/projects/handoffs/{$handoffA->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/pages/HandoffDetailPage')
            ->where('unreadCounts.handoffs', 0)
            ->where('handoff.id', $handoffA->id)
            ->where('handoff.submitted_by.id', $repA->id)
            ->where('handoff.assigned_pm.id', $pmAssigned->id)
            ->where('handoff.assigned_am.id', $am->id)
            ->where('can.requestChanges', true)
            ->where('can.approve', false)
        );

    $this->actingAs($pmUnassigned)
        ->postJson("/api/internal/handoffs/{$handoffA->id}/request-changes", ['review_feedback' => 'No access'])
        ->assertForbidden();

    $this->actingAs($repA)
        ->postJson("/api/internal/handoffs/{$handoffA->id}/request-changes", ['review_feedback' => 'No access'])
        ->assertForbidden();

    // PM cannot approve or reject
    $this->actingAs($pmAssigned)
        ->postJson("/api/internal/handoffs/{$handoffA->id}/approve", [
            'project_template_id' => $template->id,
            'assigned_pm_id' => $pmAssigned->id,
        ])
        ->assertForbidden();

    $this->actingAs($pmAssigned)
        ->postJson("/api/internal/handoffs/{$handoffA->id}/reject", ['review_feedback' => 'Nope'])
        ->assertForbidden();

    // Reviewers (OpsMgr, Director, Admin, Superadmin) get pending_review count (1) in unreadCounts.handoffs and see both queue items
    foreach ([$opsMgr, $directorA, $admin, $superadmin] as $reviewer) {
        $this->actingAs($reviewer)
            ->get('/app/projects/handoffs')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('projects/pages/HandoffIntakePage')
                ->where('unreadCounts.handoffs', 1)
                ->has('handoffs', 2)
            );
    }

    // Queue status filter (?status=pending_review) filters cleanly
    $this->actingAs($opsMgr)
        ->get('/app/projects/handoffs?status=pending_review')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/pages/HandoffIntakePage')
            ->where('activeStatus', DealHandoffStatusEnum::PendingReview->value)
            ->has('handoffs', 1)
            ->where('handoffs.0.id', $handoffA->id)
        );

    // Non-reviewers (Sales, AM, PM, Support) get 0 in unreadCounts.handoffs on /app
    foreach ([$repA, $am, $pmAssigned, $support] as $nonReviewer) {
        $this->actingAs($nonReviewer)
            ->get('/app')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('unreadCounts.handoffs', 0)
            );
    }

    // Templates endpoint: authorized for OpsMgr, Director, Admin, Superadmin; forbidden for Sales, PM, AM
    $this->actingAs($opsMgr)
        ->getJson('/api/internal/project-templates')
        ->assertOk()
        ->assertJsonCount(1, 'templates')
        ->assertJsonPath('templates.0.id', $template->id);

    $this->actingAs($directorA)
        ->getJson('/api/internal/project-templates')
        ->assertOk()
        ->assertJsonCount(1, 'templates');

    foreach ([$repA, $pmAssigned, $am] as $nonTemplateViewer) {
        $this->actingAs($nonTemplateViewer)
            ->getJson('/api/internal/project-templates')
            ->assertForbidden();
    }

    // Suspended / offboarded / soft-deleted users fail closed across handoff endpoints
    $suspendedReviewer = createHandoffWorkflowUser($dept, 'EMP-RB-SREV', 'srev.rb@acme.internal', RoleEnum::OperationsManager, null, UserStatusEnum::Suspended);
    $offboardedRep = createHandoffWorkflowUser($dept, 'EMP-RB-OREP', 'orep.rb@acme.internal', RoleEnum::Sales, null, UserStatusEnum::Offboarded);
    $deletedAdmin = createHandoffWorkflowUser($dept, 'EMP-RB-DADM', 'dadm.rb@acme.internal', RoleEnum::Admin);
    $deletedAdmin->delete();

    foreach ([$suspendedReviewer, $offboardedRep, $deletedAdmin] as $inactiveActor) {
        $this->actingAs($inactiveActor)->get('/app/projects/handoffs')->assertRedirect('/login');
        $this->actingAs($inactiveActor)->postJson("/api/internal/handoffs/{$handoffA->id}/approve", [
            'project_template_id' => $template->id,
            'assigned_pm_id' => $pmAssigned->id,
        ])->assertForbidden();
    }
});
