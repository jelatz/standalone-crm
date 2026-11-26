<?php

use App\Modules\Core\Rbac\Enums\CollaboratorAccessLevel;
use App\Modules\Core\Rbac\Enums\CollaboratorRoleLabel;
use App\Modules\Core\Rbac\Enums\RoleEnum;
use App\Modules\Core\Users\Actions\RebuildUserHierarchyClosureAction;
use App\Modules\Core\Users\Enums\UserStatusEnum;
use App\Modules\Core\Users\Models\Department;
use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Companies\Enums\CompanyLifecycleStageEnum;
use App\Modules\Crm\Companies\Models\Company;
use App\Modules\Crm\Contacts\Models\Contact;
use App\Modules\Crm\CustomFields\Enums\CustomFieldEntityTypeEnum;
use App\Modules\Crm\CustomFields\Enums\CustomFieldTypeEnum;
use App\Modules\Crm\CustomFields\Models\CustomFieldDefinition;
use App\Modules\Crm\Deals\Enums\DealStakeholderRoleEnum;
use App\Modules\Crm\Deals\Models\Deal;
use App\Modules\Crm\Leads\Enums\LeadSourceEnum;
use App\Modules\Crm\Leads\Enums\LeadStatusEnum;
use App\Modules\Crm\Leads\Models\Lead;
use App\Modules\Crm\Pipelines\Enums\PipelineStageTypeEnum;
use App\Modules\Crm\Pipelines\Models\Pipeline;
use App\Modules\Crm\Timeline\Enums\ActivityTypeEnum;
use App\Modules\Crm\Timeline\Models\Activity;
use App\Modules\Crm\Timeline\Models\ActivityLink;
use Database\Seeders\CrmDefaultsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

function createLeadsTestUser(
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

it('supports lead CRUD, custom fields, and blocks direct status patch to converted', function (): void {
    $dept = Department::query()->create([
        'name' => 'Revenue',
        'slug' => 'revenue-'.Str::lower(Str::random(4)),
    ]);

    $rep = createLeadsTestUser($dept, 'REP-L1', 'rep-l1@example.com', RoleEnum::Sales);

    CustomFieldDefinition::query()->create([
        'entity_type' => CustomFieldEntityTypeEnum::Lead,
        'key' => 'lead_score',
        'label' => 'Lead Score',
        'field_type' => CustomFieldTypeEnum::Number,
        'validation_rules' => ['min' => 0, 'max' => 100],
        'is_filterable' => true,
        'is_active' => true,
        'sort_order' => 1,
    ]);

    $createResponse = $this->actingAs($rep)
        ->postJson('/api/internal/crm/leads', [
            'first_name' => 'Elena',
            'last_name' => 'Vance',
            'email' => 'Elena.Vance@Northstar.io',
            'phone' => '+1-415-555-0142',
            'job_title' => 'VP Operations',
            'company_name' => 'Northstar Robotics',
            'website' => 'https://northstar.io',
            'status' => LeadStatusEnum::Contacted->value,
            'source' => LeadSourceEnum::Referral->value,
            'estimated_value_cents' => 4500000,
            'currency' => 'usd',
            'custom_fields' => ['lead_score' => 88],
        ])
        ->assertCreated();

    $leadId = $createResponse->json('id');
    expect($leadId)->toBeString();
    expect($createResponse->json('email'))->toBe('elena.vance@northstar.io');
    expect($createResponse->json('currency'))->toBe('USD');
    expect($createResponse->json('custom_fields.lead_score'))->toBe(88);

    // Direct status patch to 'converted' must be rejected via validation
    $this->actingAs($rep)
        ->patchJson("/api/internal/crm/leads/{$leadId}", [
            'status' => LeadStatusEnum::Converted->value,
        ])
        ->assertUnprocessable();

    $this->actingAs($rep)
        ->patchJson("/api/internal/crm/leads/{$leadId}", [
            'status' => LeadStatusEnum::Qualified->value,
            'estimated_value_cents' => 5200000,
            'custom_fields' => ['lead_score' => 94],
        ])
        ->assertOk()
        ->assertJsonPath('status', LeadStatusEnum::Qualified->value)
        ->assertJsonPath('estimated_value_cents', 5200000)
        ->assertJsonPath('custom_fields.lead_score', 94);

    $this->actingAs($rep)
        ->get('/app/crm/leads')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('crm/pages/LeadsIndexPage')
            ->has('leads.data', 1)
            ->where('leads.data.0.id', $leadId)
            ->where('statusCounts.qualified', 1)
        );

    $this->actingAs($rep)
        ->deleteJson("/api/internal/crm/leads/{$leadId}")
        ->assertOk()
        ->assertJson(['deleted' => true]);

    expect(Lead::query()->find($leadId))->toBeNull();
    expect(Lead::withTrashed()->find($leadId)?->deleted_at)->not->toBeNull();
});

it('records notes and queries timeline scoped to lead visibility', function (): void {
    $dept = Department::query()->create([
        'name' => 'Revenue Notes',
        'slug' => 'rev-notes-'.Str::lower(Str::random(4)),
    ]);

    $repA = createLeadsTestUser($dept, 'REP-N1', 'rep-n1@example.com', RoleEnum::Sales);
    $repB = createLeadsTestUser($dept, 'REP-N2', 'rep-n2@example.com', RoleEnum::Sales);

    $lead = Lead::query()->create([
        'owner_id' => $repA->id,
        'first_name' => 'Marcus',
        'last_name' => 'Shaw',
        'company_name' => 'Helios Cloud',
        'status' => LeadStatusEnum::Working,
        'source' => LeadSourceEnum::Outbound,
    ]);

    $this->actingAs($repA)
        ->postJson('/api/internal/crm/notes', [
            'body_json' => [
                'type' => 'doc',
                'content' => [
                    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Spoke with Marcus about Q3 rollout.']]],
                ],
            ],
            'targets' => [
                ['linkable_type' => 'lead', 'linkable_id' => (string) $lead->id],
            ],
        ])
        ->assertCreated();

    $this->actingAs($repA)
        ->getJson("/api/internal/crm/timeline?linkable_type=lead&linkable_id={$lead->id}")
        ->assertOk()
        ->assertJsonPath('items.0.activity_type', ActivityTypeEnum::NoteCreated->value);

    // Sibling rep in same department cannot query timeline for private lead
    $this->actingAs($repB)
        ->getJson("/api/internal/crm/timeline?linkable_type=lead&linkable_id={$lead->id}")
        ->assertForbidden();
});

it('enforces silo isolation, manager visibility, and collaborator grants on leads', function (): void {
    $dept = Department::query()->create([
        'name' => 'Sales Silo',
        'slug' => 'sales-silo-'.Str::lower(Str::random(4)),
    ]);

    $director = createLeadsTestUser($dept, 'DIR-L1', 'dir-l1@example.com', RoleEnum::Director);
    $repA = createLeadsTestUser($dept, 'REP-S1', 'rep-s1@example.com', RoleEnum::Sales, $director);
    $repB = createLeadsTestUser($dept, 'REP-S2', 'rep-s2@example.com', RoleEnum::Sales, $director);

    $lead = Lead::query()->create([
        'owner_id' => $repA->id,
        'first_name' => 'Nadia',
        'last_name' => 'Chen',
        'company_name' => 'Kestrel Bio',
        'status' => LeadStatusEnum::New,
        'source' => LeadSourceEnum::Event,
    ]);

    // Sibling repB is blocked until granted collaborator access
    $this->actingAs($repB)
        ->get("/app/crm/leads/{$lead->id}")
        ->assertForbidden();

    // Manager (director) can view direct report's lead
    $this->actingAs($director)
        ->get("/app/crm/leads/{$lead->id}")
        ->assertOk();

    expect(Lead::query()->visibleTo($repB)->whereKey($lead->id)->exists())->toBeFalse();
    expect(Lead::query()->visibleTo($director)->whereKey($lead->id)->exists())->toBeTrue();

    // Owner grants viewer collaborator to repB
    $this->actingAs($repA)
        ->postJson("/api/internal/crm/leads/{$lead->id}/collaborators", [
            'user_id' => (string) $repB->id,
            'access_level' => CollaboratorAccessLevel::Viewer->value,
            'role_label' => CollaboratorRoleLabel::SalesOverlay->value,
        ])
        ->assertCreated();

    $this->actingAs($repB)
        ->get("/app/crm/leads/{$lead->id}")
        ->assertOk();

    // View-only collaborator still cannot update the lead
    $this->actingAs($repB)
        ->patchJson("/api/internal/crm/leads/{$lead->id}", ['job_title' => 'CTO'])
        ->assertForbidden();

    // Owner revokes collaborator
    $this->actingAs($repA)
        ->deleteJson("/api/internal/crm/leads/{$lead->id}/collaborators/{$repB->id}")
        ->assertOk()
        ->assertJson(['revoked' => true]);

    $this->actingAs($repB)
        ->get("/app/crm/leads/{$lead->id}")
        ->assertForbidden();
});

it('atomically converts a lead into a new company, primary contact, and deal with win_probability override, and rejects double conversion', function (): void {
    $dept = Department::query()->create([
        'name' => 'Conversion Dept',
        'slug' => 'conv-dept-'.Str::lower(Str::random(4)),
    ]);

    $rep = createLeadsTestUser($dept, 'REP-C1', 'rep-c1@example.com', RoleEnum::Sales);
    $pipeline = Pipeline::query()->where('slug', 'new-business')->firstOrFail();
    $discoveryStage = $pipeline->stages()->where('name', 'Discovery')->firstOrFail();

    $lead = Lead::query()->create([
        'owner_id' => $rep->id,
        'first_name' => 'Liam',
        'last_name' => 'OConnor',
        'email' => 'liam@veridian.example',
        'phone' => '+1-212-555-0199',
        'job_title' => 'Head of IT',
        'company_name' => 'Veridian Systems',
        'website' => 'veridian.example',
        'status' => LeadStatusEnum::Qualified,
        'source' => LeadSourceEnum::InboundWeb,
        'estimated_value_cents' => 8400000,
        'currency' => 'USD',
    ]);

    $response = $this->actingAs($rep)
        ->postJson("/api/internal/crm/leads/{$lead->id}/convert", [
            'company_mode' => 'create',
            'company_name' => 'Veridian Systems Inc',
            'company_domain' => 'veridian.example',
            'is_primary_contact' => true,
            'create_deal' => true,
            'deal_title' => 'Veridian Platform Rollout',
            'pipeline_id' => (string) $pipeline->id,
            'stage_id' => (string) $discoveryStage->id,
            'amount_cents' => 8400000,
            'currency' => 'USD',
            'expected_close_date' => '2026-12-15',
            'win_probability' => 65,
        ])
        ->assertCreated();

    $companyId = $response->json('company_id');
    $contactId = $response->json('contact_id');
    $dealId = $response->json('deal_id');

    expect($companyId)->toBeString();
    expect($contactId)->toBeString();
    expect($dealId)->toBeString();
    expect($response->json('lead.status'))->toBe(LeadStatusEnum::Converted->value);

    $company = Company::query()->findOrFail($companyId);
    expect($company->lifecycle_stage)->toBe(CompanyLifecycleStageEnum::Prospect);
    expect($company->name)->toBe('Veridian Systems Inc');

    $contact = Contact::query()->findOrFail($contactId);
    expect((string) $contact->company_id)->toBe($companyId);
    expect($contact->is_primary_contact)->toBeTrue();
    expect($contact->primary_email)->toBe('liam@veridian.example');

    $deal = Deal::query()->findOrFail($dealId);
    expect($deal->title)->toBe('Veridian Platform Rollout');
    expect($deal->win_probability)->toBe(65);
    expect((string) $deal->primary_contact_id)->toBe($contactId);

    $stakeholder = DB::table('deal_contacts')
        ->where('deal_id', $dealId)
        ->where('contact_id', $contactId)
        ->first();
    expect($stakeholder)->not->toBeNull();
    expect($stakeholder->stakeholder_role)->toBe(DealStakeholderRoleEnum::DecisionMaker->value);

    $activity = Activity::query()
        ->where('activity_type', ActivityTypeEnum::LeadConverted)
        ->where('source_id', $lead->id)
        ->first();
    expect($activity)->not->toBeNull();

    $linkedTypes = ActivityLink::query()
        ->where('activity_id', $activity->id)
        ->pluck('linkable_type')
        ->all();
    expect($linkedTypes)->toContain('lead', 'company', 'contact', 'deal');

    // Double conversion must fail with 422
    $this->actingAs($rep)
        ->postJson("/api/internal/crm/leads/{$lead->id}/convert", [
            'company_mode' => 'create',
            'company_name' => 'Duplicate Try',
            'create_deal' => false,
        ])
        ->assertUnprocessable();
});

it('converts lead into existing visible company without deal, blocks cross-silo existing_company_id IDOR, and masks hidden converted records', function (): void {
    $dept = Department::query()->create([
        'name' => 'Existing Co Dept',
        'slug' => 'existing-co-'.Str::lower(Str::random(4)),
    ]);

    $repA = createLeadsTestUser($dept, 'REP-E1', 'rep-e1@example.com', RoleEnum::Sales);
    $repB = createLeadsTestUser($dept, 'REP-E2', 'rep-e2@example.com', RoleEnum::Sales);

    $repACompany = Company::query()->create([
        'name' => 'Acme Global',
        'lifecycle_stage' => CompanyLifecycleStageEnum::ActiveClient,
        'owner_id' => $repA->id,
    ]);

    $repBCompany = Company::query()->create([
        'name' => 'Secret Competitor Corp',
        'lifecycle_stage' => CompanyLifecycleStageEnum::Prospect,
        'owner_id' => $repB->id,
    ]);

    $leadA = Lead::query()->create([
        'owner_id' => $repA->id,
        'first_name' => 'Priya',
        'last_name' => 'Nair',
        'email' => 'priya@acme.example',
        'company_name' => 'Acme Global',
        'status' => LeadStatusEnum::Qualified,
        'source' => LeadSourceEnum::Partner,
    ]);

    // Cross-silo existing_company_id IDOR must be rejected with 403
    $this->actingAs($repA)
        ->postJson("/api/internal/crm/leads/{$leadA->id}/convert", [
            'company_mode' => 'existing',
            'existing_company_id' => (string) $repBCompany->id,
            'create_deal' => false,
        ])
        ->assertForbidden();

    // Valid existing visible company without creating a deal
    $convertResp = $this->actingAs($repA)
        ->postJson("/api/internal/crm/leads/{$leadA->id}/convert", [
            'company_mode' => 'existing',
            'existing_company_id' => (string) $repACompany->id,
            'is_primary_contact' => false,
            'create_deal' => false,
        ])
        ->assertCreated();

    expect($convertResp->json('company_id'))->toBe((string) $repACompany->id);
    expect($convertResp->json('deal_id'))->toBeNull();

    // Grant repB Editor collaborator access ONLY to leadA, NOT to repACompany/contact:
    // converted entities must remain masked on show, timeline metadata, and PATCH update!
    $this->actingAs($repA)
        ->postJson("/api/internal/crm/leads/{$leadA->id}/collaborators", [
            'user_id' => (string) $repB->id,
            'access_level' => CollaboratorAccessLevel::Editor->value,
            'role_label' => CollaboratorRoleLabel::Observer->value,
        ])
        ->assertCreated();

    $this->actingAs($repB)
        ->get("/app/crm/leads/{$leadA->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('crm/pages/LeadDetailPage')
            ->where('lead.converted_company_id', null)
            ->where('lead.converted_contact_id', null)
            ->where('lead.has_masked_converted_company', true)
            ->where('convertedCompany', null)
            ->where('convertedContact', null)
        );

    $this->actingAs($repB)
        ->patchJson("/api/internal/crm/leads/{$leadA->id}", ['job_title' => 'Advisor'])
        ->assertOk()
        ->assertJsonPath('converted_company_id', null)
        ->assertJsonPath('converted_contact_id', null)
        ->assertJsonPath('has_masked_converted_company', true);

    $timelineResp = $this->actingAs($repB)
        ->getJson("/api/internal/crm/timeline?linkable_type=lead&linkable_id={$leadA->id}")
        ->assertOk();

    $convertedActivity = collect($timelineResp->json('items'))
        ->firstWhere('activity_type', ActivityTypeEnum::LeadConverted->value);
    expect($convertedActivity)->not->toBeNull();
    expect($convertedActivity['metadata']['company_id'])->toBeNull();
    expect($convertedActivity['metadata']['contact_id'])->toBeNull();
    expect($convertedActivity['metadata']['deal_id'])->toBeNull();
    expect($convertedActivity['summary'])->toBe('Converted lead Priya Nair');
});

it('serves silo-scoped CrmOverviewPage with win_probability override math and returns 404 on removed workspace routes', function (): void {
    $dept = Department::query()->create([
        'name' => 'Overview Dept',
        'slug' => 'overview-'.Str::lower(Str::random(4)),
    ]);

    $repA = createLeadsTestUser($dept, 'REP-O1', 'rep-o1@example.com', RoleEnum::Sales);
    $repB = createLeadsTestUser($dept, 'REP-O2', 'rep-o2@example.com', RoleEnum::Sales);

    $pipeline = Pipeline::query()->where('slug', 'new-business')->firstOrFail();
    $proposalStage = $pipeline->stages()->where('name', 'Proposal')->firstOrFail(); // default 50%

    $companyA = Company::query()->create([
        'name' => 'Aether Works',
        'lifecycle_stage' => CompanyLifecycleStageEnum::Prospect,
        'owner_id' => $repA->id,
    ]);
    $companyB = Company::query()->create([
        'name' => 'Hidden Works',
        'lifecycle_stage' => CompanyLifecycleStageEnum::Prospect,
        'owner_id' => $repB->id,
    ]);

    // RepA has 1 qualified lead and 1 deal with 10,000,000 cents overridden to 80% probability -> 8,000,000 weighted
    Lead::query()->create([
        'owner_id' => $repA->id,
        'first_name' => 'Open',
        'last_name' => 'Lead',
        'status' => LeadStatusEnum::Qualified,
        'source' => LeadSourceEnum::InboundWeb,
    ]);
    // RepB has 1 open lead & 1 huge deal that must NOT leak into RepA's overview
    Lead::query()->create([
        'owner_id' => $repB->id,
        'first_name' => 'Hidden',
        'last_name' => 'Lead',
        'status' => LeadStatusEnum::New,
        'source' => LeadSourceEnum::InboundWeb,
    ]);

    $this->actingAs($repA)
        ->postJson('/api/internal/crm/deals', [
            'company_id' => (string) $companyA->id,
            'title' => 'Aether Override Deal',
            'pipeline_id' => (string) $pipeline->id,
            'stage_id' => (string) $proposalStage->id,
            'amount_cents' => 10000000,
            'currency' => 'USD',
            'win_probability' => 80,
        ])
        ->assertCreated();

    Deal::query()->create([
        'company_id' => $companyB->id,
        'pipeline_id' => $pipeline->id,
        'stage_id' => $proposalStage->id,
        'title' => 'Hidden Rep B Deal',
        'amount_cents' => 50000000,
        'currency' => 'USD',
        'owner_id' => $repB->id,
        'stage_entered_at' => now(),
    ]);

    $this->actingAs($repA)
        ->get('/app')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('crm/pages/CrmOverviewPage')
            ->where('overview.open_leads_count', 1)
            ->where('overview.qualified_leads_count', 1)
            ->where('overview.companies_count', 1)
            ->where('overview.open_deals_count', 1)
            ->where('overview.open_pipeline_cents', 10000000)
            ->where('overview.weighted_forecast_cents', 8000000)
        );

    foreach (['/app/projects', '/app/my-work', '/app/chat', '/app/email'] as $removedPath) {
        $this->actingAs($repA)->get($removedPath)->assertNotFound();
    }
});
