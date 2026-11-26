<?php

use App\Modules\Core\Rbac\Enums\CollaboratorAccessLevel;
use App\Modules\Core\Rbac\Enums\CollaboratorRoleLabel;
use App\Modules\Core\Rbac\Enums\RoleEnum;
use App\Modules\Core\Users\Actions\RebuildUserHierarchyClosureAction;
use App\Modules\Core\Users\Enums\UserStatusEnum;
use App\Modules\Core\Users\Models\Department;
use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Companies\Models\Company;
use App\Modules\Crm\Contacts\Models\Contact;
use App\Modules\Crm\CustomFields\Enums\CustomFieldEntityTypeEnum;
use App\Modules\Crm\CustomFields\Enums\CustomFieldTypeEnum;
use App\Modules\Crm\CustomFields\Models\CustomFieldDefinition;
use App\Modules\Crm\Deals\Enums\DealStakeholderRoleEnum;
use App\Modules\Crm\Deals\Models\Deal;
use App\Modules\Crm\Pipelines\Models\Pipeline;
use App\Modules\Crm\Timeline\Enums\ActivityTypeEnum;
use App\Modules\Crm\Timeline\Models\Activity;
use App\Modules\Crm\Timeline\Models\ActivityLink;
use Database\Seeders\CrmDefaultsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

function createTask14User(
    Department $department,
    string $code,
    string $email,
    RoleEnum $role,
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

    $user->assignRole($role->value);
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
    Cache::flush();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('enforces Deal creation, silo IDOR checks, timeline fan-out, company_id oracle protection, and stakeholder sync', function (): void {
    $dept = Department::query()->create(['name' => 'Revenue', 'slug' => 't14-deals-rev']);

    $directorA = createTask14User($dept, 'EMP-D-DIRA', 'dir.a.t14@acme.internal', RoleEnum::Director);
    $repA = createTask14User($dept, 'EMP-D-REPA', 'rep.a.t14@acme.internal', RoleEnum::Sales, $directorA);
    $repB = createTask14User($dept, 'EMP-D-REPB', 'rep.b.t14@acme.internal', RoleEnum::Sales);
    $amUser = createTask14User($dept, 'EMP-D-AM', 'am.t14@acme.internal', RoleEnum::AccountManager);
    $supportUser = createTask14User($dept, 'EMP-D-SUP', 'sup.t14@acme.internal', RoleEnum::Support);
    $itUser = createTask14User($dept, 'EMP-D-IT', 'it.t14@acme.internal', RoleEnum::It);
    $suspendedAm = createTask14User($dept, 'EMP-D-SUSP', 'susp.t14@acme.internal', RoleEnum::AccountManager, null, UserStatusEnum::Suspended);

    $zeroRole = User::query()->create([
        'employee_code' => 'EMP-D-ZERO',
        'name' => 'Zero Role',
        'email' => 'zero.t14@acme.internal',
        'department_id' => $dept->id,
        'status' => UserStatusEnum::Active,
    ]);

    $newBusiness = Pipeline::query()->where('slug', 'new-business')->firstOrFail();
    $nbStages = $newBusiness->stages()->orderBy('sort_order')->get()->keyBy('name');

    $renewal = Pipeline::query()->where('slug', 'renewal-upsell')->firstOrFail();
    $renewalFirstStage = $renewal->stages()->orderBy('sort_order')->firstOrFail();

    $companyA = Company::query()->create(['name' => 'Silo A Acme', 'owner_id' => $repA->id]);
    $companyB = Company::query()->create(['name' => 'Silo B Globex', 'owner_id' => $repB->id]);

    $contactA = Contact::query()->create([
        'company_id' => $companyA->id,
        'owner_id' => $repA->id,
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'primary_email' => 'ada@acme.internal',
    ]);
    $contactB = Contact::query()->create([
        'company_id' => $companyB->id,
        'owner_id' => $repB->id,
        'first_name' => 'Grace',
        'last_name' => 'Hopper',
        'primary_email' => 'grace@globex.internal',
    ]);

    // Auth & RBAC rejections
    $this->get('/app/crm/deals')->assertRedirect('/login');
    $this->actingAs($zeroRole)->get('/app/crm/deals')->assertForbidden();
    $this->actingAs($itUser)->get('/app/crm/deals')->assertForbidden();
    $this->actingAs($supportUser)->get('/app/crm/deals')->assertOk();
    $this->actingAs($supportUser)->postJson('/api/internal/crm/deals', [
        'company_id' => $companyA->id,
        'title' => 'Support Blocked',
    ])->assertForbidden();

    // Silo IDOR on create: Rep B cannot link Deal to Rep A's Company or Contact
    $this->actingAs($repB)->postJson('/api/internal/crm/deals', [
        'company_id' => $companyA->id,
        'title' => 'IDOR Company Deal',
    ])->assertForbidden();

    $this->actingAs($repB)->postJson('/api/internal/crm/deals', [
        'company_id' => $companyB->id,
        'primary_contact_id' => $contactA->id,
        'title' => 'IDOR Contact Deal',
    ])->assertForbidden();

    // Suspended account manager rejected with 422
    $this->actingAs($repA)->postJson('/api/internal/crm/deals', [
        'company_id' => $companyA->id,
        'account_manager_id' => $suspendedAm->id,
        'title' => 'Bad AM Deal',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['account_manager_id']);

    // Cross-pipeline stage_id rejected with 422
    $this->actingAs($repA)->postJson('/api/internal/crm/deals', [
        'company_id' => $companyA->id,
        'pipeline_id' => $newBusiness->id,
        'stage_id' => $renewalFirstStage->id,
        'title' => 'Cross Pipeline Stage',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['stage_id']);

    // Creating directly in gated stage (Negotiation) without required_core_fields rejected with 422
    $this->actingAs($repA)->postJson('/api/internal/crm/deals', [
        'company_id' => $companyA->id,
        'pipeline_id' => $newBusiness->id,
        'stage_id' => $nbStages['Negotiation']->id,
        'title' => 'Ungated Direct Create',
        'amount_cents' => 0,
    ])->assertUnprocessable()
        ->assertJsonPath('missing_core_fields', ['expected_close_date', 'amount_cents']);

    // Creating directly in Closed Won without stage-required custom field (deployment_region) rejected with 422
    $this->actingAs($repA)->postJson('/api/internal/crm/deals', [
        'company_id' => $companyA->id,
        'pipeline_id' => $newBusiness->id,
        'stage_id' => $nbStages['Closed Won']->id,
        'title' => 'Ungated Closed Won Create',
        'amount_cents' => 50000,
    ])->assertUnprocessable()
        ->assertJsonPath('missing_custom_fields', ['deployment_region'])
        ->assertJsonValidationErrors(['custom_fields.deployment_region']);

    // Rep A creates Deal A cleanly in default stage with custom fields + AM
    $createRes = $this->actingAs($repA)->postJson('/api/internal/crm/deals', [
        'company_id' => $companyA->id,
        'primary_contact_id' => $contactA->id,
        'pipeline_id' => $newBusiness->id,
        'account_manager_id' => $amUser->id,
        'title' => 'Acme Enterprise Expansion',
        'amount_cents' => 150000,
        'currency' => 'usd',
        'custom_fields' => [
            'contract_term_months' => 24,
            'deployment_region' => 'us_east',
        ],
    ])->assertCreated()
        ->assertJsonPath('title', 'Acme Enterprise Expansion')
        ->assertJsonPath('amount_cents', 150000)
        ->assertJsonPath('currency', 'USD')
        ->assertJsonPath('version', 1)
        ->assertJsonPath('board_position', 65536)
        ->assertJsonPath('stage_id', $nbStages['Qualification']->id);

    $dealAId = (string) $createRes->json('id');

    // Timeline fan-out to deal, company, and primary contact
    $createdActivity = Activity::query()
        ->where('source_type', 'deal')
        ->where('source_id', $dealAId)
        ->where('activity_type', ActivityTypeEnum::DealCreated->value)
        ->first();

    expect($createdActivity)->not->toBeNull();
    $linkTargets = ActivityLink::query()
        ->where('activity_id', $createdActivity->id)
        ->get()
        ->map(static fn (ActivityLink $l): string => "{$l->linkable_type}:{$l->linkable_id}")
        ->all();

    expect($linkTargets)->toEqualCanonicalizing([
        "deal:{$dealAId}",
        "company:{$companyA->id}",
        "contact:{$contactA->id}",
    ]);

    // Rep B creates Deal B in Silo B under Company B
    $dealBRes = $this->actingAs($repB)->postJson('/api/internal/crm/deals', [
        'company_id' => $companyB->id,
        'primary_contact_id' => $contactB->id,
        'pipeline_id' => $newBusiness->id,
        'title' => 'Globex Secret Deal',
        'amount_cents' => 275000,
    ])->assertCreated();
    $dealBId = (string) $dealBRes->json('id');

    // Silo isolation on Kanban & Detail before collaborator grant
    $this->actingAs($repB)->get("/app/crm/deals/{$dealAId}")->assertForbidden();
    $this->actingAs($repA)->get("/app/crm/deals/{$dealBId}")->assertForbidden();

    // Rep A shares Deal A with Rep B as Viewer, WITHOUT sharing Company A or Contact A
    $this->actingAs($repA)->postJson("/api/internal/crm/deals/{$dealAId}/collaborators", [
        'user_id' => $repB->id,
        'access_level' => CollaboratorAccessLevel::Viewer->value,
        'role_label' => CollaboratorRoleLabel::Observer->value,
    ])->assertCreated();

    // No company_id filter oracle: Rep B filtering by Company A (which Rep B cannot view) gets 0 deals on BOTH kanban & table views!
    $this->actingAs($repB)->get("/app/crm/deals?company_id={$companyA->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('crm/pages/DealsIndexPage')
            ->where('board.total_deals_count', 0));

    $this->actingAs($repB)->get("/app/crm/deals?view=table&company_id={$companyA->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('crm/pages/DealsIndexPage')
            ->where('view', 'table')
            ->has('tableDeals.data', 0));

    // When viewing all visible deals, Rep B sees Deal B + shared Deal A, with Company A & Contact A masked on Deal A!
    $this->actingAs($repB)->get("/app/crm/deals/{$dealAId}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('crm/pages/DealDetailPage')
            ->where('deal.id', $dealAId)
            ->where('deal.company_id', null)
            ->where('deal.company_name', null)
            ->where('deal.primary_contact_id', null)
            ->where('company', null)
            ->where('primaryContact', null));

    // Rep A views DealsIndexPage in table view and filters via JSONB @> custom_fields
    $this->actingAs($repA)->get('/app/crm/deals?view=table&custom_fields[deployment_region]=us_east')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('crm/pages/DealsIndexPage')
            ->where('view', 'table')
            ->has('tableDeals.data', 1)
            ->where('tableDeals.data.0.id', $dealAId));

    // Reject non-filterable / unknown custom field filters and invalid sort params with 422
    $this->actingAs($repA)->getJson('/app/crm/deals?custom_fields[unknown_cf]=x')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['custom_fields.unknown_cf']);

    $this->actingAs($repA)->getJson('/app/crm/deals?view=table&sort_by=invalid_column')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['sort_by']);

    // Multi-pipeline switching renders Renewal / Upsell board cleanly
    $renewalDealRes = $this->actingAs($repA)->postJson('/api/internal/crm/deals', [
        'company_id' => $companyA->id,
        'pipeline_id' => $renewal->id,
        'title' => 'Q4 Renewal Expansion',
        'amount_cents' => 88000,
    ])->assertCreated();

    $this->actingAs($repA)->get("/app/crm/deals?pipeline_id={$renewal->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('crm/pages/DealsIndexPage')
            ->where('board.pipeline.id', $renewal->id)
            ->where('board.total_deals_count', 1)
            ->where('board.columns.0.deals.0.id', $renewalDealRes->json('id')));

    // Stakeholder sync rejects out-of-silo Contact B and accepts Contact A
    $this->actingAs($repA)->putJson("/api/internal/crm/deals/{$dealAId}/contacts", [
        'contacts' => [
            ['contact_id' => $contactB->id, 'stakeholder_role' => DealStakeholderRoleEnum::Champion->value],
        ],
    ])->assertForbidden();

    $this->actingAs($repA)->putJson("/api/internal/crm/deals/{$dealAId}/contacts", [
        'contacts' => [
            ['contact_id' => $contactA->id, 'stakeholder_role' => DealStakeholderRoleEnum::DecisionMaker->value],
        ],
    ])->assertOk()
        ->assertJsonPath('contacts.0.contact_id', $contactA->id)
        ->assertJsonPath('contacts.0.stakeholder_role', 'decision_maker');

    // Revoke collaborator & delete Deal A
    $this->actingAs($repA)->deleteJson("/api/internal/crm/deals/{$dealAId}/collaborators/{$repB->id}")->assertOk();
    $this->actingAs($repB)->get("/app/crm/deals/{$dealAId}")->assertForbidden();

    $this->actingAs($directorA)->deleteJson("/api/internal/crm/deals/{$dealAId}")
        ->assertOk()
        ->assertJsonPath('deleted', true);
    expect(Deal::withTrashed()->find($dealAId)?->trashed())->toBeTrue();
});

it('handles Kanban MoveDealStageAction fractional positioning and automatic column rebalance when gap < 0.0001', function (): void {
    $dept = Department::query()->create(['name' => 'Enterprise', 'slug' => 't14-kanban-ent']);
    $repA = createTask14User($dept, 'EMP-K-REPA', 'rep.k.t14@acme.internal', RoleEnum::Sales);
    $repB = createTask14User($dept, 'EMP-K-REPB', 'rep.kb.t14@acme.internal', RoleEnum::Sales);

    $pipeline = Pipeline::query()->where('slug', 'new-business')->firstOrFail();
    $stages = $pipeline->stages()->orderBy('sort_order')->get()->keyBy('name');
    $qualStage = $stages['Qualification'];
    $discStage = $stages['Discovery'];

    $company = Company::query()->create(['name' => 'Kanban Co', 'owner_id' => $repA->id]);
    $companyB = Company::query()->create(['name' => 'Silo B Co', 'owner_id' => $repB->id]);

    $d1 = $this->actingAs($repA)->postJson('/api/internal/crm/deals', [
        'company_id' => $company->id,
        'pipeline_id' => $pipeline->id,
        'stage_id' => $qualStage->id,
        'title' => 'Deal One',
        'amount_cents' => 10000,
    ])->assertCreated()->json();

    $d2 = $this->actingAs($repA)->postJson('/api/internal/crm/deals', [
        'company_id' => $company->id,
        'pipeline_id' => $pipeline->id,
        'stage_id' => $qualStage->id,
        'title' => 'Deal Two',
        'amount_cents' => 20000,
    ])->assertCreated()->json();

    $d3 = $this->actingAs($repA)->postJson('/api/internal/crm/deals', [
        'company_id' => $company->id,
        'pipeline_id' => $pipeline->id,
        'stage_id' => $qualStage->id,
        'title' => 'Deal Three',
        'amount_cents' => 30000,
    ])->assertCreated()->json();

    expect((float) $d1['board_position'])->toBe(65536.0)
        ->and((float) $d2['board_position'])->toBe(131072.0)
        ->and((float) $d3['board_position'])->toBe(196608.0);

    // Move D3 between D1 and D2 within Qualification -> midpoint (65536 + 131072)/2 = 98304
    $moveMid = $this->actingAs($repA)->patchJson("/api/internal/crm/deals/{$d3['id']}/move-stage", [
        'target_stage_id' => $qualStage->id,
        'expected_version' => 1,
        'before_deal_id' => $d1['id'],
        'after_deal_id' => $d2['id'],
    ])->assertOk();

    expect((float) $moveMid->json('board_position'))->toBe(98304.0)
        ->and($moveMid->json('version'))->toBe(2);

    // Move D2 to top before D1 -> midpoint (0 + 65536)/2 = 32768
    $moveTop = $this->actingAs($repA)->patchJson("/api/internal/crm/deals/{$d2['id']}/move-stage", [
        'target_stage_id' => $qualStage->id,
        'expected_version' => 1,
        'before_deal_id' => null,
        'after_deal_id' => $d1['id'],
    ])->assertOk();

    expect((float) $moveTop->json('board_position'))->toBe(32768.0);

    // Board returns Qualification deals ordered [D2, D1, D3] with summed total and accurate rotting flag
    Deal::query()->whereKey($d1['id'])->update(['stage_entered_at' => now()->subDays(20)]);

    $this->actingAs($repA)->get("/app/crm/deals?pipeline_id={$pipeline->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('crm/pages/DealsIndexPage')
            ->where('board.columns.0.deals_count', 3)
            ->where('board.columns.0.total_amount_cents', 60000)
            ->where('board.columns.0.deals.0.id', $d2['id'])
            ->where('board.columns.0.deals.0.is_rotting', false)
            ->where('board.columns.0.deals.1.id', $d1['id'])
            ->where('board.columns.0.deals.1.is_rotting', true)
            ->where('board.columns.0.deals.2.id', $d3['id']));

    // Rebalance trigger: two adjacent deals in Discovery with gap < 0.0001
    $tightA = Deal::query()->create([
        'company_id' => $company->id,
        'pipeline_id' => $pipeline->id,
        'stage_id' => $discStage->id,
        'owner_id' => $repA->id,
        'title' => 'Tight A',
        'board_position' => 100.0,
        'stage_entered_at' => now(),
    ]);
    $tightB = Deal::query()->create([
        'company_id' => $company->id,
        'pipeline_id' => $pipeline->id,
        'stage_id' => $discStage->id,
        'owner_id' => $repA->id,
        'title' => 'Tight B',
        'board_position' => 100.00005,
        'stage_entered_at' => now(),
    ]);

    $rebalancedMove = $this->actingAs($repA)->patchJson("/api/internal/crm/deals/{$d3['id']}/move-stage", [
        'target_stage_id' => $discStage->id,
        'expected_version' => 2,
        'before_deal_id' => $tightA->id,
        'after_deal_id' => $tightB->id,
    ])->assertOk();

    expect((float) $tightA->fresh()->board_position)->toBe(65536.0)
        ->and((float) $tightB->fresh()->board_position)->toBe(131072.0)
        ->and((float) $rebalancedMove->json('board_position'))->toBe(98304.0)
        ->and($rebalancedMove->json('stage_id'))->toBe($discStage->id)
        ->and($rebalancedMove->json('version'))->toBe(3);

    // Cross-silo neighbor deal ID rejected (prevents probing Silo B deal positions)
    $siloBDeal = Deal::query()->create([
        'company_id' => $companyB->id,
        'pipeline_id' => $pipeline->id,
        'stage_id' => $discStage->id,
        'owner_id' => $repB->id,
        'title' => 'Silo B Neighbor',
        'board_position' => 500000.0,
        'stage_entered_at' => now(),
    ]);

    $this->actingAs($repA)->patchJson("/api/internal/crm/deals/{$d1['id']}/move-stage", [
        'target_stage_id' => $discStage->id,
        'expected_version' => 1,
        'before_deal_id' => $siloBDeal->id,
    ])->assertNotFound();

    // Cross-pipeline stage_id IDOR rejected with 404
    $otherPipeline = Pipeline::query()->where('slug', 'renewal-upsell')->firstOrFail();
    $otherStage = $otherPipeline->stages()->firstOrFail();

    $this->actingAs($repA)->patchJson("/api/internal/crm/deals/{$d1['id']}/move-stage", [
        'target_stage_id' => $otherStage->id,
        'expected_version' => 1,
    ])->assertNotFound();
});

it('enforces unified 422 stage-gate validation, inline field recovery, Won/Lost timestamps, and Director override_gates RBAC', function (): void {
    $dept = Department::query()->create(['name' => 'Commercial', 'slug' => 't14-gates-comm']);
    $directorA = createTask14User($dept, 'EMP-G-DIRA', 'dir.g.t14@acme.internal', RoleEnum::Director);
    $repA = createTask14User($dept, 'EMP-G-REPA', 'rep.g.t14@acme.internal', RoleEnum::Sales, $directorA);

    $pipeline = Pipeline::query()->where('slug', 'new-business')->firstOrFail();
    $stages = $pipeline->stages()->orderBy('sort_order')->get()->keyBy('name');

    $company = Company::query()->create(['name' => 'Gate Corp', 'owner_id' => $repA->id]);
    $contact = Contact::query()->create([
        'company_id' => $company->id,
        'owner_id' => $repA->id,
        'first_name' => 'Linus',
        'last_name' => 'Torvalds',
    ]);

    CustomFieldDefinition::query()->create([
        'entity_type' => CustomFieldEntityTypeEnum::Deal,
        'pipeline_id' => $pipeline->id,
        'key' => 'procurement_approved',
        'label' => 'Procurement Approved',
        'field_type' => CustomFieldTypeEnum::Boolean,
        'is_filterable' => true,
        'is_active' => true,
        'sort_order' => 5,
    ]);

    $deal = Deal::query()->create([
        'company_id' => $company->id,
        'primary_contact_id' => $contact->id,
        'pipeline_id' => $pipeline->id,
        'stage_id' => $stages['Qualification']->id,
        'owner_id' => $repA->id,
        'title' => 'Gate Tested Deal',
        'amount_cents' => 0,
        'expected_close_date' => null,
        'stage_entered_at' => now(),
        'version' => 1,
    ]);

    // 1. Move to Negotiation without amount_cents (>0) and expected_close_date -> 422
    $this->actingAs($repA)->patchJson("/api/internal/crm/deals/{$deal->id}/move-stage", [
        'target_stage_id' => $stages['Negotiation']->id,
        'expected_version' => 1,
    ])->assertUnprocessable()
        ->assertJsonPath('missing_core_fields', ['expected_close_date', 'amount_cents'])
        ->assertJsonPath('missing_custom_fields', [])
        ->assertJsonValidationErrors(['expected_close_date', 'amount_cents']);

    // Form update (PATCH /deals/{deal}) attempting same stage move hits exact same 422 gate!
    $this->actingAs($repA)->patchJson("/api/internal/crm/deals/{$deal->id}", [
        'expected_version' => 1,
        'stage_id' => $stages['Negotiation']->id,
    ])->assertUnprocessable()
        ->assertJsonPath('missing_core_fields', ['expected_close_date', 'amount_cents']);

    expect($deal->fresh()->stage_id)->toBe($stages['Qualification']->id)
        ->and($deal->fresh()->version)->toBe(1);

    // 2. Sales Rep CANNOT override gates (403 Forbidden)
    $this->actingAs($repA)->patchJson("/api/internal/crm/deals/{$deal->id}/move-stage", [
        'target_stage_id' => $stages['Negotiation']->id,
        'expected_version' => 1,
        'override_gates' => true,
    ])->assertForbidden();

    // 3. Inline core_field_updates recovery succeeds and updates stage + version + StageChanged timeline fan-out
    $this->actingAs($repA)->patchJson("/api/internal/crm/deals/{$deal->id}/move-stage", [
        'target_stage_id' => $stages['Negotiation']->id,
        'expected_version' => 1,
        'core_field_updates' => [
            'amount_cents' => 320000,
            'expected_close_date' => '2026-12-20',
        ],
    ])->assertOk()
        ->assertJsonPath('stage_id', $stages['Negotiation']->id)
        ->assertJsonPath('amount_cents', 320000)
        ->assertJsonPath('expected_close_date', '2026-12-20')
        ->assertJsonPath('version', 2);

    $stageActivity = Activity::query()
        ->where('source_type', 'deal')
        ->where('source_id', $deal->id)
        ->where('activity_type', ActivityTypeEnum::StageChanged->value)
        ->latest('occurred_at')
        ->first();
    expect($stageActivity)->not->toBeNull();

    // 4. Move to Closed Won without required custom field deployment_region -> 422
    $this->actingAs($repA)->patchJson("/api/internal/crm/deals/{$deal->id}/move-stage", [
        'target_stage_id' => $stages['Closed Won']->id,
        'expected_version' => 2,
    ])->assertUnprocessable()
        ->assertJsonPath('missing_core_fields', [])
        ->assertJsonPath('missing_custom_fields', ['deployment_region'])
        ->assertJsonValidationErrors(['custom_fields.deployment_region']);

    // 5. Inline custom_field_updates recovery -> Closed Won + won_at set
    $this->actingAs($repA)->patchJson("/api/internal/crm/deals/{$deal->id}/move-stage", [
        'target_stage_id' => $stages['Closed Won']->id,
        'expected_version' => 2,
        'custom_field_updates' => [
            'deployment_region' => 'eu_central',
            'procurement_approved' => true,
        ],
    ])->assertOk()
        ->assertJsonPath('stage_id', $stages['Closed Won']->id)
        ->assertJsonPath('custom_fields.deployment_region', 'eu_central')
        ->assertJsonPath('custom_fields.procurement_approved', true)
        ->assertJsonPath('version', 3);

    $wonDeal = $deal->fresh();
    expect($wonDeal->won_at)->not->toBeNull()
        ->and($wonDeal->lost_at)->toBeNull();

    // Moving to Closed Lost clears won_at and sets lost_at
    $this->actingAs($repA)->patchJson("/api/internal/crm/deals/{$deal->id}/move-stage", [
        'target_stage_id' => $stages['Closed Lost']->id,
        'expected_version' => 3,
        'core_field_updates' => ['lost_reason' => 'Budget frozen'],
    ])->assertOk()
        ->assertJsonPath('lost_reason', 'Budget frozen')
        ->assertJsonPath('version', 4);

    $lostDeal = $deal->fresh();
    expect($lostDeal->lost_at)->not->toBeNull()
        ->and($lostDeal->won_at)->toBeNull();

    // 6. Director CAN override_gates on a fresh empty Deal
    $overrideDeal = Deal::query()->create([
        'company_id' => $company->id,
        'pipeline_id' => $pipeline->id,
        'stage_id' => $stages['Qualification']->id,
        'owner_id' => $repA->id,
        'title' => 'Executive Override Deal',
        'amount_cents' => 0,
        'stage_entered_at' => now(),
        'version' => 1,
    ]);

    $this->actingAs($directorA)->patchJson("/api/internal/crm/deals/{$overrideDeal->id}/move-stage", [
        'target_stage_id' => $stages['Closed Won']->id,
        'expected_version' => 1,
        'override_gates' => true,
    ])->assertOk()
        ->assertJsonPath('stage_id', $stages['Closed Won']->id)
        ->assertJsonPath('version', 2);

    $overrideActivity = Activity::query()
        ->where('source_type', 'deal')
        ->where('source_id', $overrideDeal->id)
        ->where('activity_type', ActivityTypeEnum::StageChanged->value)
        ->firstOrFail();
    expect($overrideActivity->metadata['gate_overridden'] ?? false)->toBeTrue();
});

it('returns 409 Conflict with fresh entity payload on stale version in both UpdateDealAction and MoveDealStageAction', function (): void {
    $dept = Department::query()->create(['name' => 'Sales', 'slug' => 't14-lock-sales']);
    $repA = createTask14User($dept, 'EMP-L-REPA', 'rep.l.t14@acme.internal', RoleEnum::Sales);
    $repB = createTask14User($dept, 'EMP-L-REPB', 'rep.lb.t14@acme.internal', RoleEnum::Sales);

    $pipeline = Pipeline::query()->where('slug', 'new-business')->firstOrFail();
    $stages = $pipeline->stages()->orderBy('sort_order')->get()->keyBy('name');

    $company = Company::query()->create(['name' => 'Lock Co', 'owner_id' => $repA->id]);
    $deal = Deal::query()->create([
        'company_id' => $company->id,
        'pipeline_id' => $pipeline->id,
        'stage_id' => $stages['Qualification']->id,
        'owner_id' => $repA->id,
        'title' => 'Original Title',
        'amount_cents' => 50000,
        'stage_entered_at' => now(),
        'version' => 1,
    ]);

    // Collaborator viewer vs editor boundaries
    $this->actingAs($repA)->postJson("/api/internal/crm/deals/{$deal->id}/collaborators", [
        'user_id' => $repB->id,
        'access_level' => CollaboratorAccessLevel::Viewer->value,
        'role_label' => CollaboratorRoleLabel::Observer->value,
    ])->assertCreated();

    $this->actingAs($repB)->patchJson("/api/internal/crm/deals/{$deal->id}", [
        'expected_version' => 1,
        'title' => 'Viewer Blocked',
    ])->assertForbidden();
    $this->actingAs($repB)->patchJson("/api/internal/crm/deals/{$deal->id}/move-stage", [
        'target_stage_id' => $stages['Discovery']->id,
        'expected_version' => 1,
    ])->assertForbidden();

    // Upgrade Rep B to Editor -> updates title (version 1 -> 2)
    $this->actingAs($repA)->postJson("/api/internal/crm/deals/{$deal->id}/collaborators", [
        'user_id' => $repB->id,
        'access_level' => CollaboratorAccessLevel::Editor->value,
        'role_label' => CollaboratorRoleLabel::SalesOverlay->value,
    ])->assertCreated();

    $this->actingAs($repB)->patchJson("/api/internal/crm/deals/{$deal->id}", [
        'expected_version' => 1,
        'title' => 'Updated By Editor',
    ])->assertOk()
        ->assertJsonPath('version', 2)
        ->assertJsonPath('title', 'Updated By Editor');

    // Rep A tries to move stage using stale version=1 -> 409 Conflict with fresh entity (version=2) & DB untouched
    $this->actingAs($repA)->patchJson("/api/internal/crm/deals/{$deal->id}/move-stage", [
        'target_stage_id' => $stages['Discovery']->id,
        'expected_version' => 1,
    ])->assertConflict()
        ->assertJsonPath('current_version', 2)
        ->assertJsonPath('entity.id', $deal->id)
        ->assertJsonPath('entity.version', 2)
        ->assertJsonPath('entity.title', 'Updated By Editor');

    // Rep A tries to update fields using stale version=1 -> 409 Conflict
    $this->actingAs($repA)->patchJson("/api/internal/crm/deals/{$deal->id}", [
        'expected_version' => 1,
        'title' => 'Stale Overwrite Attempt',
    ])->assertConflict()
        ->assertJsonPath('current_version', 2)
        ->assertJsonPath('entity.title', 'Updated By Editor');

    $fresh = $deal->fresh();
    expect($fresh->version)->toBe(2)
        ->and($fresh->stage_id)->toBe($stages['Qualification']->id)
        ->and($fresh->title)->toBe('Updated By Editor');
});

it('denies override_gates for Sales in UpdateDealAction even when stage_id is unchanged or omitted, while enforcing canonical stage-then-deal lock ordering', function (): void {
    $dept = Department::query()->create(['name' => 'Locking', 'slug' => 't14-lock-order']);
    $director = createTask14User($dept, 'EMP-LO-DIR', 'dir.lo.t14@acme.internal', RoleEnum::Director);
    $rep = createTask14User($dept, 'EMP-LO-REP', 'rep.lo.t14@acme.internal', RoleEnum::Sales, $director);

    $pipeline = Pipeline::query()->where('slug', 'new-business')->firstOrFail();
    $stages = $pipeline->stages()->orderBy('sort_order')->get()->keyBy('name');
    $qualStage = $stages['Qualification'];
    $discStage = $stages['Discovery'];
    $negStage = $stages['Negotiation'];

    $otherPipeline = Pipeline::query()->where('slug', 'renewal-upsell')->firstOrFail();
    $otherStage = $otherPipeline->stages()->firstOrFail();

    $company = Company::query()->create(['name' => 'Order Corp', 'owner_id' => $rep->id]);

    $neighborA = Deal::query()->create([
        'company_id' => $company->id,
        'pipeline_id' => $pipeline->id,
        'stage_id' => $discStage->id,
        'owner_id' => $rep->id,
        'title' => 'Neighbor A',
        'board_position' => 100000.0,
        'stage_entered_at' => now(),
        'version' => 1,
    ]);

    $neighborB = Deal::query()->create([
        'company_id' => $company->id,
        'pipeline_id' => $pipeline->id,
        'stage_id' => $discStage->id,
        'owner_id' => $rep->id,
        'title' => 'Neighbor B',
        'board_position' => 200000.0,
        'stage_entered_at' => now(),
        'version' => 1,
    ]);

    if ((string) $neighborA->id < (string) $neighborB->id) {
        $neighborLowerUuid = $neighborA;
        $neighborHigherUuid = $neighborB;
    } else {
        $neighborLowerUuid = $neighborB;
        $neighborHigherUuid = $neighborA;
    }

    // Ensure before_deal_id has the lexicographically higher UUID so sorted locking is explicitly exercised.
    $neighborHigherUuid->update(['board_position' => 100000.0]);
    $neighborLowerUuid->update(['board_position' => 200000.0]);

    expect((string) $neighborHigherUuid->id > (string) $neighborLowerUuid->id)->toBeTrue();

    $deal = Deal::query()->create([
        'company_id' => $company->id,
        'pipeline_id' => $pipeline->id,
        'stage_id' => $qualStage->id,
        'owner_id' => $rep->id,
        'title' => 'Order Tested Deal',
        'amount_cents' => 0,
        'stage_entered_at' => now(),
        'version' => 1,
    ]);

    // 1. Sales rep cannot send override_gates=true via UpdateDealAction when stage_id is omitted
    $this->actingAs($rep)->patchJson("/api/internal/crm/deals/{$deal->id}", [
        'expected_version' => 1,
        'title' => 'Sneaky Override Title',
        'override_gates' => true,
    ])->assertForbidden();

    // 2. Sales rep cannot send override_gates=true via UpdateDealAction when stage_id equals current stage
    $this->actingAs($rep)->patchJson("/api/internal/crm/deals/{$deal->id}", [
        'expected_version' => 1,
        'stage_id' => $qualStage->id,
        'title' => 'Same Stage Override',
        'override_gates' => true,
    ])->assertForbidden();

    // 3. Sales rep cannot send override_gates=true via UpdateDealAction when stage_id changes to gated stage
    $this->actingAs($rep)->patchJson("/api/internal/crm/deals/{$deal->id}", [
        'expected_version' => 1,
        'stage_id' => $negStage->id,
        'override_gates' => true,
    ])->assertForbidden();

    expect($deal->fresh()->title)->toBe('Order Tested Deal')
        ->and($deal->fresh()->stage_id)->toBe($qualStage->id)
        ->and($deal->fresh()->version)->toBe(1);

    // 4. Cross-pipeline stage_id on UpdateDealAction fails closed with 404
    $this->actingAs($rep)->patchJson("/api/internal/crm/deals/{$deal->id}", [
        'expected_version' => 1,
        'stage_id' => $otherStage->id,
    ])->assertNotFound();

    // 5. Capture FOR UPDATE query sequence across MoveDealStageAction & UpdateDealAction
    /** @var list<array{sql: string, bindings: array<int|string, mixed>}> $forUpdateQueries */
    $forUpdateQueries = [];
    DB::listen(static function (QueryExecuted $query) use (&$forUpdateQueries): void {
        if (str_contains(strtolower($query->sql), 'for update')) {
            $forUpdateQueries[] = [
                'sql' => $query->sql,
                'bindings' => $query->bindings,
            ];
        }
    });

    // MoveDealStageAction with reversed UUID neighbor inputs locks: targetStage -> deal -> sorted(lowerUuid, higherUuid)
    $this->actingAs($rep)->patchJson("/api/internal/crm/deals/{$deal->id}/move-stage", [
        'target_stage_id' => $discStage->id,
        'expected_version' => 1,
        'before_deal_id' => $neighborHigherUuid->id,
        'after_deal_id' => $neighborLowerUuid->id,
    ])->assertOk()
        ->assertJsonPath('stage_id', $discStage->id)
        ->assertJsonPath('board_position', 150000)
        ->assertJsonPath('version', 2);

    expect($forUpdateQueries)->toHaveCount(4)
        ->and($forUpdateQueries[0]['sql'])->toContain('"pipeline_stages"')
        ->and($forUpdateQueries[0]['bindings'])->toContain((string) $discStage->id)
        ->and($forUpdateQueries[1]['sql'])->toContain('"deals"')
        ->and($forUpdateQueries[1]['bindings'])->toContain((string) $deal->id)
        ->and($forUpdateQueries[2]['sql'])->toContain('"deals"')
        ->and($forUpdateQueries[2]['bindings'])->toContain((string) $neighborLowerUuid->id)
        ->and($forUpdateQueries[3]['sql'])->toContain('"deals"')
        ->and($forUpdateQueries[3]['bindings'])->toContain((string) $neighborHigherUuid->id);

    // UpdateDealAction with stage_id + Director override_gates locks targetStage BEFORE deal
    $forUpdateQueries = [];

    $this->actingAs($director)->patchJson("/api/internal/crm/deals/{$deal->id}", [
        'expected_version' => 2,
        'stage_id' => $negStage->id,
        'override_gates' => true,
    ])->assertOk()
        ->assertJsonPath('stage_id', $negStage->id)
        ->assertJsonPath('version', 3);

    expect($forUpdateQueries)->toHaveCount(2)
        ->and($forUpdateQueries[0]['sql'])->toContain('"pipeline_stages"')
        ->and($forUpdateQueries[0]['bindings'])->toContain((string) $negStage->id)
        ->and($forUpdateQueries[1]['sql'])->toContain('"deals"')
        ->and($forUpdateQueries[1]['bindings'])->toContain((string) $deal->id);
});
