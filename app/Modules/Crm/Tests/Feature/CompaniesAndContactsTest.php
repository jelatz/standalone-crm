<?php

use App\Modules\Core\Attachments\Enums\AttachmentScanStatus;
use App\Modules\Core\Attachments\Models\Attachment;
use App\Modules\Core\Attachments\Models\AttachmentLink;
use App\Modules\Core\Rbac\Enums\CollaboratorAccessLevel;
use App\Modules\Core\Rbac\Enums\CollaboratorRoleLabel;
use App\Modules\Core\Rbac\Enums\RoleEnum;
use App\Modules\Core\Users\Actions\RebuildUserHierarchyClosureAction;
use App\Modules\Core\Users\Enums\UserStatusEnum;
use App\Modules\Core\Users\Models\Department;
use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Companies\Actions\ListCompaniesAction;
use App\Modules\Crm\Companies\Data\CompanyFilterData;
use App\Modules\Crm\Companies\Enums\CompanyLifecycleStageEnum;
use App\Modules\Crm\Companies\Models\Company;
use App\Modules\Crm\Contacts\Models\Contact;
use App\Modules\Crm\Contacts\Models\ContactEmail;
use App\Modules\Crm\CustomFields\Enums\CustomFieldEntityTypeEnum;
use App\Modules\Crm\CustomFields\Enums\CustomFieldTypeEnum;
use App\Modules\Crm\CustomFields\Models\CustomFieldDefinition;
use App\Modules\Crm\Deals\Enums\DealStakeholderRoleEnum;
use App\Modules\Crm\Deals\Models\Deal;
use App\Modules\Crm\Pipelines\Models\Pipeline;
use App\Modules\Crm\Timeline\Actions\RecordActivityAction;
use App\Modules\Crm\Timeline\Enums\ActivityTypeEnum;
use App\Modules\Crm\Timeline\Models\Activity;
use Database\Seeders\CrmDefaultsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

function createTask13User(
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

it('enforces Companies CRUD, validation, silo hierarchy, account manager assignment, timeline activity, and role permissions', function (): void {
    $dept = Department::query()->create(['name' => 'Revenue', 'slug' => 't13-companies-rev']);

    $admin = createTask13User($dept, 'EMP-C-ADM', 'admin.t13@acme.internal', RoleEnum::Admin);
    $directorA = createTask13User($dept, 'EMP-C-DIRA', 'dir.a.t13@acme.internal', RoleEnum::Director);
    $repA = createTask13User($dept, 'EMP-C-REPA', 'rep.a.t13@acme.internal', RoleEnum::Sales, $directorA);
    $repB = createTask13User($dept, 'EMP-C-REPB', 'rep.b.t13@acme.internal', RoleEnum::Sales);
    $amUser = createTask13User($dept, 'EMP-C-AM', 'am.t13@acme.internal', RoleEnum::AccountManager);
    $suspendedAm = createTask13User($dept, 'EMP-C-SUSP', 'susp.am@acme.internal', RoleEnum::AccountManager, null, UserStatusEnum::Suspended);
    $trashedAm = createTask13User($dept, 'EMP-C-DEL', 'del.am@acme.internal', RoleEnum::AccountManager);
    $trashedAm->delete();

    $zeroRole = User::query()->create([
        'employee_code' => 'EMP-C-ZERO',
        'name' => 'Zero Role',
        'email' => 'zero.t13@acme.internal',
        'department_id' => $dept->id,
        'status' => UserStatusEnum::Active,
    ]);

    // Unauthenticated and zero-role rejections
    $this->get('/app/crm/companies')->assertRedirect('/login');
    $this->actingAs($zeroRole)->get('/app/crm/companies')->assertForbidden();
    $this->actingAs($zeroRole)->postJson('/api/internal/crm/companies', ['name' => 'Blocked'])->assertForbidden();

    // AccountManager lacks companies.create
    $this->actingAs($amUser)->postJson('/api/internal/crm/companies', ['name' => 'AM Blocked'])->assertForbidden();

    // Reject inactive/trashed account_manager_id and invalid custom fields
    $this->actingAs($repA)->postJson('/api/internal/crm/companies', [
        'name' => 'Bad AM Co',
        'account_manager_id' => $suspendedAm->id,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['account_manager_id']);

    $this->actingAs($repA)->postJson('/api/internal/crm/companies', [
        'name' => 'Bad Trashed AM Co',
        'account_manager_id' => $trashedAm->id,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['account_manager_id']);

    $this->actingAs($repA)->postJson('/api/internal/crm/companies', [
        'name' => 'Bad Custom Field Co',
        'custom_fields' => ['tier' => 'mythical_tier'],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['custom_fields.tier']);

    // Rep A creates Company A with valid payload + active AM
    $createRes = $this->actingAs($repA)->postJson('/api/internal/crm/companies', [
        'name' => '  Northwind Logistics  ',
        'domain' => 'Northwind.IO',
        'lifecycle_stage' => CompanyLifecycleStageEnum::ActiveClient->value,
        'industry' => 'Supply Chain',
        'employee_range' => '501-1000',
        'annual_revenue_cents' => 450000000,
        'billing_address' => [
            'line1' => '100 Harbor Blvd',
            'city' => 'Seattle',
            'country' => 'US',
        ],
        'account_manager_id' => $amUser->id,
        'custom_fields' => ['tier' => 'enterprise'],
    ])->assertCreated()
        ->assertJsonPath('name', 'Northwind Logistics')
        ->assertJsonPath('domain', 'Northwind.IO')
        ->assertJsonPath('lifecycle_stage', 'active_client')
        ->assertJsonPath('owner_id', $repA->id)
        ->assertJsonPath('account_manager_id', $amUser->id)
        ->assertJsonPath('custom_fields.tier', 'enterprise');

    $companyAId = (string) $createRes->json('id');

    // Rep B creates Company B in Silo B
    $companyBRes = $this->actingAs($repB)->postJson('/api/internal/crm/companies', [
        'name' => 'Silo B Biotech',
        'domain' => 'silob.example',
        'lifecycle_stage' => CompanyLifecycleStageEnum::Prospect->value,
    ])->assertCreated();
    $companyBId = (string) $companyBRes->json('id');

    // CompaniesIndexPage renders silo-scoped list + assignableUsers in silo
    $this->actingAs($repA)->get('/app/crm/companies')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('crm/pages/CompaniesIndexPage')
            ->has('companies.data', 1)
            ->where('companies.data.0.id', $companyAId)
            ->has('filterDefinitions', 1)
            ->has('assignableUsers', 1)
            ->where('assignableUsers.0.id', $repA->id)
            ->has('lifecycleStages', 5));

    // Director A sees Rep A's Company AND both Director A + Rep A in assignableUsers (never Rep B)
    $this->actingAs($directorA)->get('/app/crm/companies')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('crm/pages/CompaniesIndexPage')
            ->has('companies.data', 1)
            ->where('companies.data.0.id', $companyAId)
            ->has('assignableUsers', 2));

    // Assigned AM can view and update Company A, but CANNOT delete (lacks companies.delete)
    $this->actingAs($amUser)->get("/app/crm/companies/{$companyAId}")->assertOk();
    $this->actingAs($amUser)->patchJson("/api/internal/crm/companies/{$companyAId}", [
        'industry' => 'Global Freight & Logistics',
        'custom_fields' => ['tier' => 'mid_market'],
    ])->assertOk()
        ->assertJsonPath('industry', 'Global Freight & Logistics')
        ->assertJsonPath('custom_fields.tier', 'mid_market');

    expect(Activity::query()
        ->where('source_type', 'company')
        ->where('source_id', $companyAId)
        ->where('activity_type', ActivityTypeEnum::FieldUpdated->value)
        ->exists())->toBeTrue();

    $this->actingAs($amUser)->deleteJson("/api/internal/crm/companies/{$companyAId}")->assertForbidden();

    // Cross-silo Rep B cannot view, update, or delete Company A (403)
    $this->actingAs($repB)->get("/app/crm/companies/{$companyAId}")->assertForbidden();
    $this->actingAs($repB)->patchJson("/api/internal/crm/companies/{$companyAId}", ['name' => 'Hijack'])->assertForbidden();
    $this->actingAs($repB)->deleteJson("/api/internal/crm/companies/{$companyAId}")->assertForbidden();

    // Director A (or Rep A) can soft-delete Company A; Admin sees Company B
    $this->actingAs($directorA)->deleteJson("/api/internal/crm/companies/{$companyAId}")
        ->assertOk()
        ->assertJsonPath('deleted', true);

    expect(Company::withTrashed()->find($companyAId)?->trashed())->toBeTrue();
    $this->actingAs($repA)->get("/app/crm/companies/{$companyAId}")->assertNotFound();

    $this->actingAs($admin)->get('/app/crm/companies')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('companies.data', 1)
            ->where('companies.data.0.id', $companyBId));
});

it('manages Company collaborator sharing and isolates Silo B Deals and activities on CompanyDetailPage', function (): void {
    $dept = Department::query()->create(['name' => 'Enterprise', 'slug' => 't13-collab-ent']);
    $admin = createTask13User($dept, 'EMP-CD-ADM', 'admin.cd@acme.internal', RoleEnum::Admin);
    $repA = createTask13User($dept, 'EMP-CD-REPA', 'rep.a.cd@acme.internal', RoleEnum::Sales);
    $repB = createTask13User($dept, 'EMP-CD-REPB', 'rep.b.cd@acme.internal', RoleEnum::Sales);
    $suspendedUser = createTask13User($dept, 'EMP-CD-SUSP', 'susp.cd@acme.internal', RoleEnum::Sales, null, UserStatusEnum::Suspended);

    $pipeline = Pipeline::query()->where('slug', 'new-business')->firstOrFail();
    $stage = $pipeline->stages()->orderBy('sort_order')->firstOrFail();

    $company = Company::query()->create([
        'name' => 'Shared Meridian Corp',
        'domain' => 'meridian.internal',
        'owner_id' => $repA->id,
    ]);

    $contactA = Contact::query()->create([
        'company_id' => $company->id,
        'owner_id' => $repA->id,
        'first_name' => 'Elena',
        'last_name' => 'Rostova',
        'primary_email' => 'elena@meridian.internal',
    ]);

    $dealA = Deal::query()->create([
        'company_id' => $company->id,
        'primary_contact_id' => $contactA->id,
        'pipeline_id' => $pipeline->id,
        'stage_id' => $stage->id,
        'owner_id' => $repA->id,
        'title' => 'Rep A Confidential Expansion',
        'amount_cents' => 500000,
        'stage_entered_at' => now(),
    ]);

    $dealB = Deal::query()->create([
        'company_id' => $company->id,
        'pipeline_id' => $pipeline->id,
        'stage_id' => $stage->id,
        'owner_id' => $repB->id,
        'title' => 'Rep B Separate Division Deal',
        'amount_cents' => 120000,
        'stage_entered_at' => now(),
    ]);

    $attachment = Attachment::query()->create([
        'uploaded_by_id' => $repA->id,
        'disk' => 's3',
        'bucket' => 'crm-files',
        'object_key' => 'companies/msa.pdf',
        'original_filename' => 'msa.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 2048,
        'sha256' => hash('sha256', 'msa'),
        'scan_status' => AttachmentScanStatus::Clean,
        'created_at' => now(),
    ]);

    AttachmentLink::query()->create([
        'attachment_id' => $attachment->id,
        'attachable_type' => 'company',
        'attachable_id' => $company->id,
        'created_at' => now(),
    ]);

    app(RecordActivityAction::class)->execute(
        actor: $repA,
        activityType: ActivityTypeEnum::DealCreated,
        sourceType: 'deal',
        sourceId: $dealA->id,
        targets: ['deal' => $dealA->id, 'company' => $company->id],
        summary: 'Secret Deal A created',
    );

    app(RecordActivityAction::class)->execute(
        actor: $repB,
        activityType: ActivityTypeEnum::DealCreated,
        sourceType: 'deal',
        sourceId: $dealB->id,
        targets: ['deal' => $dealB->id, 'company' => $company->id],
        summary: 'Deal B created by Rep B',
    );

    // Rep B cannot manage collaborators or view Company prior to grant
    $this->actingAs($repB)->postJson("/api/internal/crm/companies/{$company->id}/collaborators", [
        'user_id' => $repB->id,
        'access_level' => CollaboratorAccessLevel::Manager->value,
        'role_label' => CollaboratorRoleLabel::SalesOverlay->value,
    ])->assertForbidden();

    // Cannot grant collaborator access to suspended user
    $this->actingAs($repA)->postJson("/api/internal/crm/companies/{$company->id}/collaborators", [
        'user_id' => $suspendedUser->id,
        'access_level' => CollaboratorAccessLevel::Viewer->value,
        'role_label' => CollaboratorRoleLabel::Observer->value,
    ])->assertUnprocessable();

    // Rep A grants Rep B Viewer access on Shared Meridian Corp
    $this->actingAs($repA)->postJson("/api/internal/crm/companies/{$company->id}/collaborators", [
        'user_id' => $repB->id,
        'access_level' => CollaboratorAccessLevel::Viewer->value,
        'role_label' => CollaboratorRoleLabel::SalesOverlay->value,
    ])->assertCreated()
        ->assertJsonPath('user_id', $repB->id)
        ->assertJsonPath('access_level', 'viewer');

    // Rep B can view CompanyDetailPage, sees ONLY Deal B (NEVER Deal A!), sees attachment, sees only Deal B activity
    $this->actingAs($repB)->get("/app/crm/companies/{$company->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('crm/pages/CompanyDetailPage')
            ->where('company.id', $company->id)
            ->has('contacts', 0)
            ->has('deals', 1)
            ->where('deals.0.id', $dealB->id)
            ->where('deals.0.title', 'Rep B Separate Division Deal')
            ->has('collaborators', 1)
            ->has('attachments', 1)
            ->has('customFieldDefinitions', 1)
            ->has('timeline.items', 1)
            ->where('timeline.items.0.summary', 'Deal B created by Rep B'));

    // Rep A sees ONLY Deal A (NEVER Deal B!) on CompanyDetailPage
    $this->actingAs($repA)->get("/app/crm/companies/{$company->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('crm/pages/CompanyDetailPage')
            ->has('contacts', 1)
            ->has('deals', 1)
            ->where('deals.0.id', $dealA->id)
            ->has('timeline.items', 1)
            ->where('timeline.items.0.summary', 'Secret Deal A created'));

    // Admin sees BOTH Deal A and Deal B
    $this->actingAs($admin)->get("/app/crm/companies/{$company->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('deals', 2)
            ->has('timeline.items', 2));

    // Viewer Rep B cannot update or manage collaborators
    $this->actingAs($repB)->patchJson("/api/internal/crm/companies/{$company->id}", ['industry' => 'Fintech'])->assertForbidden();
    $this->actingAs($repB)->deleteJson("/api/internal/crm/companies/{$company->id}/collaborators/{$repB->id}")->assertForbidden();

    // Upgrade Rep B to Editor -> update succeeds, delete still 403
    $this->actingAs($repA)->postJson("/api/internal/crm/companies/{$company->id}/collaborators", [
        'user_id' => $repB->id,
        'access_level' => CollaboratorAccessLevel::Editor->value,
        'role_label' => CollaboratorRoleLabel::SalesOverlay->value,
    ])->assertCreated();

    $this->actingAs($repB)->patchJson("/api/internal/crm/companies/{$company->id}", ['industry' => 'Fintech'])->assertOk();
    $this->actingAs($repB)->deleteJson("/api/internal/crm/companies/{$company->id}")->assertForbidden();

    // Revoke Rep B -> immediate 403 on CompanyDetailPage
    $this->actingAs($repA)->deleteJson("/api/internal/crm/companies/{$company->id}/collaborators/{$repB->id}")
        ->assertOk()
        ->assertJsonPath('revoked', true);

    $this->actingAs($repB)->get("/app/crm/companies/{$company->id}")->assertForbidden();
});

it('filters Companies and Contacts via PostgreSQL jsonb @> containment using GIN index and rejects non-filterable keys with 422', function (): void {
    $dept = Department::query()->create(['name' => 'Ops', 'slug' => 't13-gin-ops']);
    $admin = createTask13User($dept, 'EMP-CFQ-ADM', 'admin.cfq@acme.internal', RoleEnum::Admin);
    $repA = createTask13User($dept, 'EMP-CFQ-A', 'rep.a.cfq@acme.internal', RoleEnum::Sales);
    $repB = createTask13User($dept, 'EMP-CFQ-B', 'rep.b.cfq@acme.internal', RoleEnum::Sales);

    CustomFieldDefinition::query()->create([
        'entity_type' => CustomFieldEntityTypeEnum::Company,
        'key' => 'target_seats',
        'label' => 'Target Seats',
        'field_type' => CustomFieldTypeEnum::Number,
        'is_filterable' => true,
        'is_active' => true,
        'sort_order' => 2,
    ]);

    CustomFieldDefinition::query()->create([
        'entity_type' => CustomFieldEntityTypeEnum::Company,
        'key' => 'internal_notes',
        'label' => 'Internal Notes',
        'field_type' => CustomFieldTypeEnum::Text,
        'is_filterable' => false,
        'is_active' => true,
        'sort_order' => 3,
    ]);

    CustomFieldDefinition::query()->create([
        'entity_type' => CustomFieldEntityTypeEnum::Company,
        'key' => 'archived_flag',
        'label' => 'Archived Flag',
        'field_type' => CustomFieldTypeEnum::Boolean,
        'is_filterable' => true,
        'is_active' => false,
        'sort_order' => 4,
    ]);

    $entCo = Company::query()->create([
        'name' => 'Alpha Enterprise',
        'domain' => 'alpha.example',
        'owner_id' => $repA->id,
        'custom_fields' => ['tier' => 'enterprise', 'target_seats' => 250, 'internal_notes' => 'vip'],
    ]);
    Company::query()->create([
        'name' => 'Beta SMB',
        'domain' => 'beta.example',
        'owner_id' => $repA->id,
        'custom_fields' => ['tier' => 'smb', 'target_seats' => 25],
    ]);
    Company::query()->create([
        'name' => 'Gamma Silo B Enterprise',
        'domain' => 'gamma.example',
        'owner_id' => $repB->id,
        'custom_fields' => ['tier' => 'enterprise', 'target_seats' => 250],
    ]);

    // Filterable select + numeric custom field containment + silo scoping
    $this->actingAs($repA)->get('/app/crm/companies?custom_fields[tier]=enterprise&custom_fields[target_seats]=250')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('companies.data', 1)
            ->where('companies.data.0.id', $entCo->id));

    // Search ILIKE on citext domain & name
    $this->actingAs($repA)->get('/app/crm/companies?search=BETA.EXAMPLE')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('companies.data', 1)
            ->where('companies.data.0.name', 'Beta SMB'));

    // Non-filterable, inactive, and unknown custom_fields keys rejected with 422
    $this->actingAs($repA)->getJson('/app/crm/companies?custom_fields[internal_notes]=vip')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['custom_fields.internal_notes']);

    $this->actingAs($repA)->getJson('/app/crm/companies?custom_fields[archived_flag]=true')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['custom_fields.archived_flag']);

    $this->actingAs($repA)->getJson('/app/crm/companies?custom_fields[nonexistent]=1')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['custom_fields.nonexistent']);

    // Seeded Contact field 'linkedin_url' has is_filterable = false -> rejected with 422
    $this->actingAs($repA)->getJson('/app/crm/contacts?custom_fields[linkedin_url]=https://linkedin.com/in/ada')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['custom_fields.linkedin_url']);

    // Seed sufficient rows + ANALYZE so PostgreSQL planner selects GIN index idx_companies_cf natively
    $bulkRows = [];
    for ($i = 0; $i < 1500; $i++) {
        $bulkRows[] = [
            'id' => Str::uuid7()->toString(),
            'name' => "Bulk Co {$i}",
            'lifecycle_stage' => 'prospect',
            'billing_address' => '{}',
            'owner_id' => $repB->id,
            'custom_fields' => json_encode(['tier' => 'smb'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
    DB::table('companies')->insert($bulkRows);
    DB::statement('ANALYZE companies');

    // PostgreSQL EXPLAIN (FORMAT JSON) verifying GIN-compatible @> containment query on companies.custom_fields
    $query = app(ListCompaniesAction::class)->buildQuery(
        $admin,
        new CompanyFilterData(custom_fields: ['tier' => 'enterprise']),
    );
    $sql = $query->toSql();
    $bindings = $query->getBindings();

    expect($sql)->toContain('@>');

    $rows = DB::select('EXPLAIN (FORMAT JSON) '.$sql, $bindings);
    $planPayload = (string) ($rows[0]->{'QUERY PLAN'} ?? '[]');

    $plan = json_decode($planPayload, true, 32, JSON_THROW_ON_ERROR);
    expect($plan)->toBeArray()->not->toBeEmpty()
        ->and($planPayload)->toContain('idx_companies_cf')
        ->and($planPayload)->toContain('Bitmap Index Scan')
        ->and($planPayload)->toContain('@>');
});

it('enforces Contacts CRUD, silo-verified company linking, atomic citext contact_emails sync, search, collaborators, and ContactDetailPage deal isolation', function (): void {
    $dept = Department::query()->create(['name' => 'Commercial', 'slug' => 't13-contacts-comm']);
    $admin = createTask13User($dept, 'EMP-CT-ADM', 'admin.ct@acme.internal', RoleEnum::Admin);
    $repA = createTask13User($dept, 'EMP-CT-REPA', 'rep.a.ct@acme.internal', RoleEnum::Sales);
    $repB = createTask13User($dept, 'EMP-CT-REPB', 'rep.b.ct@acme.internal', RoleEnum::Sales);

    $pipeline = Pipeline::query()->where('slug', 'new-business')->firstOrFail();
    $stage = $pipeline->stages()->orderBy('sort_order')->firstOrFail();

    CustomFieldDefinition::query()->create([
        'entity_type' => CustomFieldEntityTypeEnum::Contact,
        'key' => 'persona',
        'label' => 'Buyer Persona',
        'field_type' => CustomFieldTypeEnum::Select,
        'options' => [
            ['value' => 'economic', 'label' => 'Economic Buyer'],
            ['value' => 'technical', 'label' => 'Technical Buyer'],
        ],
        'is_filterable' => true,
        'is_active' => true,
        'sort_order' => 2,
    ]);

    $companyA = Company::query()->create(['name' => 'Company A', 'domain' => 'companya.io', 'owner_id' => $repA->id]);
    $companyB = Company::query()->create(['name' => 'Company B', 'domain' => 'companyb.io', 'owner_id' => $repB->id]);

    // Cross-silo company_id IDOR rejected on create (Rep B cannot link Contact to Rep A's Company A)
    $this->actingAs($repB)->postJson('/api/internal/crm/contacts', [
        'company_id' => $companyA->id,
        'first_name' => 'IDOR',
        'last_name' => 'Attempt',
        'primary_email' => 'idor@companya.io',
    ])->assertForbidden();

    // Rep A creates Contact A with duplicate/mixed-case primary & secondary emails
    $contactRes = $this->actingAs($repA)->postJson('/api/internal/crm/contacts', [
        'company_id' => $companyA->id,
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'primary_email' => 'Ada.Lovelace@CompanyA.IO',
        'secondary_emails' => [
            'ada.lovelace@companya.io',
            'Ada.Alt@CompanyA.IO',
            'billing@companya.io',
            'BILLING@COMPANYA.IO',
        ],
        'phones' => ['+1-555-0100'],
        'job_title' => 'VP Engineering',
        'is_primary_contact' => true,
        'custom_fields' => [
            'linkedin_url' => 'https://linkedin.com/in/adalovelace',
            'persona' => 'technical',
        ],
    ])->assertCreated()
        ->assertJsonPath('full_name', 'Ada Lovelace')
        ->assertJsonPath('primary_email', 'Ada.Lovelace@CompanyA.IO')
        ->assertJsonPath('custom_fields.persona', 'technical');

    $contactAId = (string) $contactRes->json('id');

    $emailRows = ContactEmail::query()->where('contact_id', $contactAId)->get();
    expect($emailRows)->toHaveCount(3)
        ->and($emailRows->where('is_primary', true))->toHaveCount(1)
        ->and($emailRows->firstWhere('is_primary', true)?->email)->toBe('Ada.Lovelace@CompanyA.IO')
        ->and($emailRows->where('is_primary', false)->pluck('email')->all())->toEqualCanonicalizing([
            'Ada.Alt@CompanyA.IO',
            'billing@companya.io',
        ]);

    // Rep B creates Contact B on Company B, then tries to re-link to Company A via PATCH -> 403
    $contactBRes = $this->actingAs($repB)->postJson('/api/internal/crm/contacts', [
        'company_id' => $companyB->id,
        'first_name' => 'Grace',
        'last_name' => 'Hopper',
        'primary_email' => 'grace@companyb.io',
    ])->assertCreated();
    $contactBId = (string) $contactBRes->json('id');

    $this->actingAs($repB)->patchJson("/api/internal/crm/contacts/{$contactBId}", [
        'company_id' => $companyA->id,
    ])->assertForbidden();
    expect(Contact::query()->findOrFail($contactBId)->company_id)->toBe($companyB->id);

    // Promote an existing secondary email to primary_email without sending secondary_emails -> no duplicate unique error
    $this->actingAs($repA)->patchJson("/api/internal/crm/contacts/{$contactAId}", [
        'primary_email' => 'Ada.Alt@CompanyA.IO',
    ])->assertOk()
        ->assertJsonPath('primary_email', 'Ada.Alt@CompanyA.IO');

    $afterPromote = ContactEmail::query()->where('contact_id', $contactAId)->get();
    expect($afterPromote)->toHaveCount(2)
        ->and($afterPromote->where('is_primary', true))->toHaveCount(1)
        ->and($afterPromote->firstWhere('is_primary', true)?->email)->toBe('Ada.Alt@CompanyA.IO')
        ->and($afterPromote->where('is_primary', false)->pluck('email')->all())->toBe(['billing@companya.io']);

    // Replace secondary_emails atomically
    $this->actingAs($repA)->patchJson("/api/internal/crm/contacts/{$contactAId}", [
        'secondary_emails' => ['exec@companya.io'],
    ])->assertOk();

    expect(ContactEmail::query()->where('contact_id', $contactAId)->where('is_primary', false)->pluck('email')->all())
        ->toBe(['exec@companya.io']);

    // Case-insensitive search across full_name, primary_email, and contact_emails.email + custom_fields filter
    $this->actingAs($repA)->get('/app/crm/contacts?search=ada+lovelace')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('crm/pages/ContactsIndexPage')
            ->has('contacts.data', 1)
            ->where('contacts.data.0.id', $contactAId));

    $this->actingAs($repA)->get('/app/crm/contacts?search=EXEC@COMPANYA.IO')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('contacts.data', 1)
            ->where('contacts.data.0.id', $contactAId));

    $this->actingAs($repA)->get('/app/crm/contacts?custom_fields[persona]=technical')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('contacts.data', 1)
            ->where('contacts.data.0.id', $contactAId));

    // Seed deals linked to Contact A: Deal A (Rep A) and Deal B (Rep B, linked via deal_contacts pivot)
    $dealA = Deal::query()->create([
        'company_id' => $companyA->id,
        'primary_contact_id' => $contactAId,
        'pipeline_id' => $pipeline->id,
        'stage_id' => $stage->id,
        'owner_id' => $repA->id,
        'title' => 'Deal A Secret',
        'stage_entered_at' => now(),
    ]);

    $dealB = Deal::query()->create([
        'company_id' => $companyB->id,
        'pipeline_id' => $pipeline->id,
        'stage_id' => $stage->id,
        'owner_id' => $repB->id,
        'title' => 'Deal B Stakeholder Link',
        'stage_entered_at' => now(),
    ]);
    $dealB->contacts()->attach($contactAId, ['stakeholder_role' => DealStakeholderRoleEnum::TechnicalBuyer->value]);

    // Rep B cannot access Contact A before collaborator share
    $this->actingAs($repB)->get("/app/crm/contacts/{$contactAId}")->assertForbidden();
    $this->actingAs($repB)->patchJson("/api/internal/crm/contacts/{$contactAId}", ['job_title' => 'CTO'])->assertForbidden();
    $this->actingAs($repB)->deleteJson("/api/internal/crm/contacts/{$contactAId}")->assertForbidden();

    // Rep A shares ONLY Contact A with Rep B (NOT Company A!)
    $this->actingAs($repA)->postJson("/api/internal/crm/contacts/{$contactAId}/collaborators", [
        'user_id' => $repB->id,
        'access_level' => CollaboratorAccessLevel::Viewer->value,
        'role_label' => CollaboratorRoleLabel::Observer->value,
    ])->assertCreated();

    // Rep B views ContactDetailPage: company link is hidden (Company A is out-of-silo!), and ONLY Deal B is visible!
    $this->actingAs($repB)->get("/app/crm/contacts/{$contactAId}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('crm/pages/ContactDetailPage')
            ->where('contact.id', $contactAId)
            ->where('company', null)
            ->has('deals', 1)
            ->where('deals.0.id', $dealB->id));

    // Rep A sees Company A + ONLY Deal A on ContactDetailPage
    $this->actingAs($repA)->get("/app/crm/contacts/{$contactAId}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('crm/pages/ContactDetailPage')
            ->where('company.id', $companyA->id)
            ->has('deals', 1)
            ->where('deals.0.id', $dealA->id));

    // Admin sees Company A + BOTH deals
    $this->actingAs($admin)->get("/app/crm/contacts/{$contactAId}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('company.id', $companyA->id)
            ->has('deals', 2));

    // Revoke collaborator & delete Contact A
    $this->actingAs($repA)->deleteJson("/api/internal/crm/contacts/{$contactAId}/collaborators/{$repB->id}")->assertOk();
    $this->actingAs($repB)->get("/app/crm/contacts/{$contactAId}")->assertForbidden();

    $this->actingAs($repA)->deleteJson("/api/internal/crm/contacts/{$contactAId}")->assertOk();
    expect(Contact::withTrashed()->find($contactAId)?->trashed())->toBeTrue();
    $this->actingAs($repA)->get("/app/crm/contacts/{$contactAId}")->assertNotFound();
});
