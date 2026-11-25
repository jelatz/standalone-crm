<?php

use App\Modules\Core\Rbac\Actions\GrantEntityCollaboratorAction;
use App\Modules\Core\Rbac\Enums\CollaboratorAccessLevel;
use App\Modules\Core\Rbac\Enums\CollaboratorRoleLabel;
use App\Modules\Core\Rbac\Enums\RoleEnum;
use App\Modules\Core\Users\Actions\RebuildUserHierarchyClosureAction;
use App\Modules\Core\Users\Enums\UserStatusEnum;
use App\Modules\Core\Users\Models\Department;
use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Companies\Enums\CompanyLifecycleStageEnum;
use App\Modules\Crm\Companies\Models\Company;
use App\Modules\Crm\Companies\Policies\CompanyPolicy;
use App\Modules\Crm\Contacts\Models\Contact;
use App\Modules\Crm\Contacts\Models\ContactEmail;
use App\Modules\Crm\Contacts\Policies\ContactPolicy;
use App\Modules\Crm\CustomFields\Enums\CustomFieldEntityTypeEnum;
use App\Modules\Crm\CustomFields\Enums\CustomFieldTypeEnum;
use App\Modules\Crm\CustomFields\Models\CustomFieldDefinition;
use App\Modules\Crm\CustomFields\Policies\CustomFieldDefinitionPolicy;
use App\Modules\Crm\Deals\Enums\DealStakeholderRoleEnum;
use App\Modules\Crm\Deals\Models\Deal;
use App\Modules\Crm\Deals\Policies\DealPolicy;
use App\Modules\Crm\Notes\Models\Note;
use App\Modules\Crm\Notes\Policies\NotePolicy;
use App\Modules\Crm\Pipelines\Enums\PipelineStageTypeEnum;
use App\Modules\Crm\Pipelines\Models\Pipeline;
use App\Modules\Crm\Pipelines\Models\PipelineStage;
use App\Modules\Crm\Pipelines\Policies\PipelinePolicy;
use App\Modules\Crm\Timeline\Enums\ActivityTypeEnum;
use App\Modules\Crm\Timeline\Models\Activity;
use App\Modules\Crm\Timeline\Models\ActivityLink;
use Database\Seeders\CrmDefaultsSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

function createCrmTestUser(
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

it('creates all 12 PostgreSQL 17 CRM tables, GIN/partial/timeline indexes, generated full_name, citext, morphMap, and policies', function (): void {
    $tables = [
        'companies',
        'contacts',
        'contact_emails',
        'pipelines',
        'pipeline_stages',
        'custom_field_definitions',
        'stage_required_fields',
        'deals',
        'deal_contacts',
        'notes',
        'activities',
        'activity_links',
    ];

    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeTrue("Expected table [{$table}] to exist.");
    }

    $companyIndexes = collect(DB::select("SELECT indexname, indexdef FROM pg_indexes WHERE tablename = 'companies'"))
        ->keyBy('indexname');
    expect($companyIndexes->keys()->all())->toContain(
        'idx_companies_owner',
        'idx_companies_am',
        'idx_companies_domain',
        'idx_companies_cf',
    )
        ->and($companyIndexes->get('idx_companies_cf')->indexdef)->toContain('USING gin', 'jsonb_path_ops')
        ->and($companyIndexes->get('idx_companies_owner')->indexdef)->toContain('WHERE (deleted_at IS NULL)')
        ->and($companyIndexes->get('idx_companies_am')->indexdef)->toContain('WHERE (deleted_at IS NULL)');

    $contactIndexes = collect(DB::select("SELECT indexname, indexdef FROM pg_indexes WHERE tablename = 'contacts'"))
        ->keyBy('indexname');
    expect($contactIndexes->keys()->all())->toContain(
        'idx_contacts_company',
        'idx_contacts_owner',
        'idx_contacts_cf',
    )
        ->and($contactIndexes->get('idx_contacts_cf')->indexdef)->toContain('USING gin', 'jsonb_path_ops');

    $contactEmailIndexes = collect(DB::select("SELECT indexname FROM pg_indexes WHERE tablename = 'contact_emails'"))
        ->pluck('indexname')
        ->all();
    expect($contactEmailIndexes)->toContain('idx_contact_emails_lookup');

    $stageIndexes = collect(DB::select("SELECT indexname FROM pg_indexes WHERE tablename = 'pipeline_stages'"))
        ->pluck('indexname')
        ->all();
    expect($stageIndexes)->toContain('idx_pipeline_stages_order');

    $dealIndexes = collect(DB::select("SELECT indexname, indexdef FROM pg_indexes WHERE tablename = 'deals'"))
        ->keyBy('indexname');
    expect($dealIndexes->keys()->all())->toContain(
        'idx_deals_kanban',
        'idx_deals_owner',
        'idx_deals_company',
        'idx_deals_cf',
    )
        ->and($dealIndexes->get('idx_deals_cf')->indexdef)->toContain('USING gin', 'jsonb_path_ops')
        ->and($dealIndexes->get('idx_deals_kanban')->indexdef)->toContain('WHERE (deleted_at IS NULL)')
        ->and($dealIndexes->get('idx_deals_owner')->indexdef)->toContain('WHERE (deleted_at IS NULL)');

    $timelineIndexes = collect(DB::select("SELECT indexname, indexdef FROM pg_indexes WHERE tablename = 'activity_links'"))
        ->keyBy('indexname');
    expect($timelineIndexes->keys()->all())->toContain('idx_activity_links_timeline')
        ->and($timelineIndexes->get('idx_activity_links_timeline')->indexdef)
        ->toContain('occurred_at DESC', 'activity_id DESC');

    // Verify morphMap and Policy registration
    expect(Relation::getMorphedModel('company'))->toBe(Company::class)
        ->and(Relation::getMorphedModel('contact'))->toBe(Contact::class)
        ->and(Relation::getMorphedModel('deal'))->toBe(Deal::class)
        ->and(Relation::getMorphedModel('note'))->toBe(Note::class)
        ->and(Relation::getMorphedModel('pipeline'))->toBe(Pipeline::class)
        ->and(Relation::getMorphedModel('pipeline_stage'))->toBe(PipelineStage::class)
        ->and(Relation::getMorphedModel('custom_field_definition'))->toBe(CustomFieldDefinition::class)
        ->and(Relation::getMorphedModel('activity'))->toBe(Activity::class)
        ->and(Gate::getPolicyFor(Company::class))->toBeInstanceOf(CompanyPolicy::class)
        ->and(Gate::getPolicyFor(Contact::class))->toBeInstanceOf(ContactPolicy::class)
        ->and(Gate::getPolicyFor(Deal::class))->toBeInstanceOf(DealPolicy::class)
        ->and(Gate::getPolicyFor(Pipeline::class))->toBeInstanceOf(PipelinePolicy::class)
        ->and(Gate::getPolicyFor(CustomFieldDefinition::class))->toBeInstanceOf(CustomFieldDefinitionPolicy::class)
        ->and(Gate::getPolicyFor(Note::class))->toBeInstanceOf(NotePolicy::class);

    // Verify contacts.full_name PostgreSQL GENERATED ALWAYS STORED column & citext behavior
    $dept = Department::query()->create(['name' => 'Sales', 'slug' => 'crm-schema-sales']);
    $owner = createCrmTestUser($dept, 'EMP-CRM-001', 'owner@acme.internal', RoleEnum::Sales);

    $company = Company::query()->create([
        'name' => 'Acme Corp',
        'domain' => 'Acme.COM',
        'lifecycle_stage' => CompanyLifecycleStageEnum::Prospect,
        'owner_id' => $owner->id,
    ]);

    expect(Str::isUuid($company->id))->toBeTrue()
        ->and(Company::query()->where('domain', 'acme.com')->exists())->toBeTrue();

    $contact = Contact::query()->create([
        'company_id' => $company->id,
        'owner_id' => $owner->id,
        'first_name' => '  Ada',
        'last_name' => 'Lovelace  ',
        'primary_email' => 'Ada@Acme.COM',
        'is_primary_contact' => true,
    ]);

    expect($contact->refresh()->full_name)->toBe('Ada Lovelace')
        ->and(Contact::query()->where('primary_email', 'ada@acme.com')->exists())->toBeTrue();

    ContactEmail::query()->create([
        'contact_id' => $contact->id,
        'email' => 'Ada@Acme.COM',
        'is_primary' => true,
    ]);

    expect(ContactEmail::query()->where('email', 'ada@acme.com')->exists())->toBeTrue();

    // Case-insensitive UNIQUE(contact_id, email) via PostgreSQL citext
    expect(fn () => DB::transaction(static fn () => ContactEmail::query()->create([
        'contact_id' => $contact->id,
        'email' => 'ada@acme.com',
        'is_primary' => false,
    ])))->toThrow(QueryException::class);

    // Verify Deal stakeholder pivot & enum casts
    $this->seed(CrmDefaultsSeeder::class);
    $pipeline = Pipeline::query()->where('slug', 'new-business')->firstOrFail();
    $stage = $pipeline->stages()->orderBy('sort_order')->firstOrFail();

    $deal = Deal::query()->create([
        'company_id' => $company->id,
        'primary_contact_id' => $contact->id,
        'pipeline_id' => $pipeline->id,
        'stage_id' => $stage->id,
        'owner_id' => $owner->id,
        'title' => 'Acme Enterprise License',
        'amount_cents' => 250000,
        'stage_entered_at' => now(),
    ]);

    $deal->contacts()->attach($contact->id, ['stakeholder_role' => DealStakeholderRoleEnum::DecisionMaker->value]);
    expect($deal->contacts()->first()?->pivot->stakeholder_role)->toBe(DealStakeholderRoleEnum::DecisionMaker->value);

    // Verify PostgreSQL JSONB @> containment queries work on custom_fields (GIN jsonb_path_ops)
    $deal->update(['custom_fields' => ['deployment_region' => 'us_east', 'contract_term_months' => 24]]);
    expect(Deal::query()->whereRaw("custom_fields @> '{\"deployment_region\": \"us_east\"}'::jsonb")->pluck('id')->all())
        ->toBe([$deal->id]);
});

it('seeds default CRM pipelines, stages, custom field definitions, and stage gates idempotently via CrmDefaultsSeeder and DatabaseSeeder', function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->seed(CrmDefaultsSeeder::class);
    $this->seed(DatabaseSeeder::class);

    expect(Pipeline::query()->count())->toBe(2)
        ->and(Pipeline::query()->where('is_default', true)->count())->toBe(1);

    $newBusiness = Pipeline::query()->where('slug', 'new-business')->firstOrFail();
    expect($newBusiness->name)->toBe('New Business')
        ->and($newBusiness->is_default)->toBeTrue()
        ->and($newBusiness->is_active)->toBeTrue();

    $nbStages = $newBusiness->stages()->orderBy('sort_order')->get();
    expect($nbStages)->toHaveCount(6)
        ->and($nbStages->pluck('name')->all())->toBe([
            'Qualification',
            'Discovery',
            'Proposal',
            'Negotiation',
            'Closed Won',
            'Closed Lost',
        ])
        ->and($nbStages[0]->win_probability)->toBe(10)
        ->and($nbStages[0]->stage_type)->toBe(PipelineStageTypeEnum::Open)
        ->and($nbStages[1]->win_probability)->toBe(25)
        ->and($nbStages[2]->win_probability)->toBe(50)
        ->and($nbStages[3]->win_probability)->toBe(75)
        ->and($nbStages[3]->required_core_fields)->toBe(['expected_close_date', 'amount_cents'])
        ->and($nbStages[4]->win_probability)->toBe(100)
        ->and($nbStages[4]->stage_type)->toBe(PipelineStageTypeEnum::Won)
        ->and($nbStages[5]->win_probability)->toBe(0)
        ->and($nbStages[5]->stage_type)->toBe(PipelineStageTypeEnum::Lost);

    $renewal = Pipeline::query()->where('slug', 'renewal-upsell')->firstOrFail();
    expect($renewal->is_default)->toBeFalse()
        ->and($renewal->stages()->count())->toBe(5);

    $contractTerm = CustomFieldDefinition::query()
        ->where('entity_type', CustomFieldEntityTypeEnum::Deal->value)
        ->where('key', 'contract_term_months')
        ->firstOrFail();
    expect($contractTerm->field_type)->toBe(CustomFieldTypeEnum::Number);

    $deploymentRegion = CustomFieldDefinition::query()
        ->where('entity_type', CustomFieldEntityTypeEnum::Deal->value)
        ->where('key', 'deployment_region')
        ->firstOrFail();
    expect($deploymentRegion->field_type)->toBe(CustomFieldTypeEnum::Select);

    expect($nbStages[4]->requiredCustomFields()->pluck('custom_field_definitions.id')->all())
        ->toContain($deploymentRegion->id);
});

it('fails closed in HasSiloVisibility when viewer is suspended, offboarded, or soft-deleted even with explicit entity_collaborators rows', function (): void {
    $this->seed(CrmDefaultsSeeder::class);
    $dept = Department::query()->create(['name' => 'Revenue', 'slug' => 'crm-silo-rev']);

    $admin = createCrmTestUser($dept, 'EMP-SILO-ADM', 'admin.silo@acme.internal', RoleEnum::Admin);
    $ownerA = createCrmTestUser($dept, 'EMP-SILO-A', 'owner.a@acme.internal', RoleEnum::Sales);
    $suspendedCollab = createCrmTestUser($dept, 'EMP-SILO-B', 'collab.susp@acme.internal', RoleEnum::Sales);
    $offboardedCollab = createCrmTestUser($dept, 'EMP-SILO-C', 'collab.off@acme.internal', RoleEnum::Sales);
    $trashedCollab = createCrmTestUser($dept, 'EMP-SILO-D', 'collab.del@acme.internal', RoleEnum::Sales);
    $activeAm = createCrmTestUser($dept, 'EMP-SILO-AM', 'am.active@acme.internal', RoleEnum::AccountManager);

    $pipeline = Pipeline::query()->where('slug', 'new-business')->firstOrFail();
    $stage = $pipeline->stages()->orderBy('sort_order')->firstOrFail();

    $company = Company::query()->create([
        'name' => 'Protected Co',
        'owner_id' => $ownerA->id,
        'account_manager_id' => $activeAm->id,
    ]);
    $contact = Contact::query()->create([
        'company_id' => $company->id,
        'owner_id' => $ownerA->id,
        'first_name' => 'Grace',
        'last_name' => 'Hopper',
    ]);
    $deal = Deal::query()->create([
        'company_id' => $company->id,
        'primary_contact_id' => $contact->id,
        'pipeline_id' => $pipeline->id,
        'stage_id' => $stage->id,
        'owner_id' => $ownerA->id,
        'account_manager_id' => $activeAm->id,
        'title' => 'Protected Deal',
        'stage_entered_at' => now(),
    ]);

    foreach ([$suspendedCollab, $offboardedCollab, $trashedCollab] as $collab) {
        foreach ([$company, $contact, $deal] as $target) {
            app(GrantEntityCollaboratorAction::class)->execute(
                collaboratable: $target,
                user: $collab,
                accessLevel: CollaboratorAccessLevel::Manager,
                roleLabel: CollaboratorRoleLabel::SalesOverlay,
                grantedBy: $ownerA,
            );
        }
    }

    // Active collaborator and AM can see records prior to deactivation
    expect(Company::query()->visibleTo($suspendedCollab)->pluck('id')->all())->toBe([$company->id])
        ->and(Contact::query()->visibleTo($suspendedCollab)->pluck('id')->all())->toBe([$contact->id])
        ->and(Deal::query()->visibleTo($suspendedCollab)->pluck('id')->all())->toBe([$deal->id])
        ->and(Company::query()->visibleTo($activeAm)->pluck('id')->all())->toBe([$company->id])
        ->and(Deal::query()->visibleTo($activeAm)->pluck('id')->all())->toBe([$deal->id]);

    // Suspend, offboard, and soft-delete collaborators AND suspend Admin + AM
    $suspendedCollab->update(['status' => UserStatusEnum::Suspended]);
    $offboardedCollab->update(['status' => UserStatusEnum::Offboarded]);
    $trashedCollab->delete();
    $admin->update(['status' => UserStatusEnum::Suspended]);
    $activeAm->update(['status' => UserStatusEnum::Suspended]);

    foreach ([$suspendedCollab, $offboardedCollab, $trashedCollab, $admin, $activeAm] as $inactiveViewer) {
        expect(Company::query()->visibleTo($inactiveViewer)->count())->toBe(0)
            ->and(Contact::query()->visibleTo($inactiveViewer)->count())->toBe(0)
            ->and(Deal::query()->visibleTo($inactiveViewer)->count())->toBe(0);
    }
});

it('enforces CompanyPolicy, ContactPolicy, and DealPolicy across hierarchy, collaborators, roles, and active status', function (): void {
    $this->seed(CrmDefaultsSeeder::class);
    $dept = Department::query()->create(['name' => 'Commercial', 'slug' => 'crm-policies-comm']);

    $admin = createCrmTestUser($dept, 'EMP-POL-ADM', 'admin.pol@acme.internal', RoleEnum::Admin);
    $directorA = createCrmTestUser($dept, 'EMP-POL-DIRA', 'dir.a.pol@acme.internal', RoleEnum::Director);
    $repA = createCrmTestUser($dept, 'EMP-POL-REPA', 'rep.a.pol@acme.internal', RoleEnum::Sales, $directorA);
    $repB = createCrmTestUser($dept, 'EMP-POL-REPB', 'rep.b.pol@acme.internal', RoleEnum::Sales);
    $amUser = createCrmTestUser($dept, 'EMP-POL-AM', 'am.pol@acme.internal', RoleEnum::AccountManager);
    $supportUser = createCrmTestUser($dept, 'EMP-POL-SUP', 'support.pol@acme.internal', RoleEnum::Support);
    $itUser = createCrmTestUser($dept, 'EMP-POL-IT', 'it.pol@acme.internal', RoleEnum::It);

    $pipeline = Pipeline::query()->where('slug', 'new-business')->firstOrFail();
    $stage = $pipeline->stages()->orderBy('sort_order')->firstOrFail();

    $company = Company::query()->create([
        'name' => 'Silo A Client',
        'owner_id' => $repA->id,
        'account_manager_id' => $amUser->id,
    ]);
    $contact = Contact::query()->create([
        'company_id' => $company->id,
        'owner_id' => $repA->id,
        'first_name' => 'Linus',
        'last_name' => 'Torvalds',
    ]);
    $deal = Deal::query()->create([
        'company_id' => $company->id,
        'primary_contact_id' => $contact->id,
        'pipeline_id' => $pipeline->id,
        'stage_id' => $stage->id,
        'owner_id' => $repA->id,
        'account_manager_id' => $amUser->id,
        'title' => 'Silo A Expansion',
        'stage_entered_at' => now(),
    ]);

    // Base viewAny / create permission checks
    expect(Gate::forUser($repA)->allows('viewAny', Company::class))->toBeTrue()
        ->and(Gate::forUser($repA)->allows('create', Deal::class))->toBeTrue()
        ->and(Gate::forUser($supportUser)->allows('viewAny', Deal::class))->toBeTrue()
        ->and(Gate::forUser($supportUser)->allows('create', Deal::class))->toBeFalse()
        ->and(Gate::forUser($itUser)->allows('viewAny', Deal::class))->toBeFalse();

    // Silo Owner (Rep A): full Company/Contact/Deal CRUD + moveStage, NO overrideGates
    foreach ([$company, $contact, $deal] as $record) {
        expect(Gate::forUser($repA)->allows('view', $record))->toBeTrue()
            ->and(Gate::forUser($repA)->allows('update', $record))->toBeTrue()
            ->and(Gate::forUser($repA)->allows('delete', $record))->toBeTrue()
            ->and(Gate::forUser($repA)->allows('manageCollaborators', $record))->toBeTrue();
    }
    expect(Gate::forUser($repA)->allows('moveStage', $deal))->toBeTrue()
        ->and(Gate::forUser($repA)->allows('overrideGates', $deal))->toBeFalse();

    // Silo Director (Director A): full silo CRUD + moveStage + overrideGates
    foreach ([$company, $contact, $deal] as $record) {
        expect(Gate::forUser($directorA)->allows('view', $record))->toBeTrue()
            ->and(Gate::forUser($directorA)->allows('update', $record))->toBeTrue()
            ->and(Gate::forUser($directorA)->allows('delete', $record))->toBeTrue()
            ->and(Gate::forUser($directorA)->allows('manageCollaborators', $record))->toBeTrue();
    }
    expect(Gate::forUser($directorA)->allows('moveStage', $deal))->toBeTrue()
        ->and(Gate::forUser($directorA)->allows('overrideGates', $deal))->toBeTrue();

    // Assigned Account Manager (AccountManager lacks companies.delete & deals.delete & deals.override_gates)
    expect(Gate::forUser($amUser)->allows('view', $company))->toBeTrue()
        ->and(Gate::forUser($amUser)->allows('update', $company))->toBeTrue()
        ->and(Gate::forUser($amUser)->allows('manageCollaborators', $company))->toBeTrue()
        ->and(Gate::forUser($amUser)->allows('delete', $company))->toBeFalse()
        ->and(Gate::forUser($amUser)->allows('view', $deal))->toBeTrue()
        ->and(Gate::forUser($amUser)->allows('update', $deal))->toBeTrue()
        ->and(Gate::forUser($amUser)->allows('moveStage', $deal))->toBeTrue()
        ->and(Gate::forUser($amUser)->allows('delete', $deal))->toBeFalse()
        ->and(Gate::forUser($amUser)->allows('overrideGates', $deal))->toBeFalse();

    // Cross-silo Rep B without collaborator grant: all denied
    foreach ([$company, $contact, $deal] as $record) {
        expect(Gate::forUser($repB)->allows('view', $record))->toBeFalse()
            ->and(Gate::forUser($repB)->allows('update', $record))->toBeFalse()
            ->and(Gate::forUser($repB)->allows('delete', $record))->toBeFalse()
            ->and(Gate::forUser($repB)->allows('manageCollaborators', $record))->toBeFalse();
    }
    expect(Gate::forUser($repB)->allows('moveStage', $deal))->toBeFalse();

    // Rep B as VIEWER collaborator: view only
    foreach ([$company, $contact, $deal] as $record) {
        app(GrantEntityCollaboratorAction::class)->execute(
            collaboratable: $record,
            user: $repB,
            accessLevel: CollaboratorAccessLevel::Viewer,
            roleLabel: CollaboratorRoleLabel::Observer,
            grantedBy: $directorA,
        );

        expect(Gate::forUser($repB)->allows('view', $record))->toBeTrue()
            ->and(Gate::forUser($repB)->allows('update', $record))->toBeFalse()
            ->and(Gate::forUser($repB)->allows('delete', $record))->toBeFalse()
            ->and(Gate::forUser($repB)->allows('manageCollaborators', $record))->toBeFalse();
    }
    expect(Gate::forUser($repB)->allows('moveStage', $deal))->toBeFalse();

    // Rep B upgraded to EDITOR collaborator: view + update + moveStage, NO delete / manageCollaborators / overrideGates
    foreach ([$company, $contact, $deal] as $record) {
        app(GrantEntityCollaboratorAction::class)->execute(
            collaboratable: $record,
            user: $repB,
            accessLevel: CollaboratorAccessLevel::Editor,
            roleLabel: CollaboratorRoleLabel::SalesOverlay,
            grantedBy: $directorA,
        );

        expect(Gate::forUser($repB)->allows('view', $record))->toBeTrue()
            ->and(Gate::forUser($repB)->allows('update', $record))->toBeTrue()
            ->and(Gate::forUser($repB)->allows('delete', $record))->toBeFalse()
            ->and(Gate::forUser($repB)->allows('manageCollaborators', $record))->toBeFalse();
    }
    expect(Gate::forUser($repB)->allows('moveStage', $deal))->toBeTrue()
        ->and(Gate::forUser($repB)->allows('overrideGates', $deal))->toBeFalse();

    // Rep B upgraded to MANAGER collaborator: view + update + delete + manageCollaborators + moveStage, still NO overrideGates
    foreach ([$company, $contact, $deal] as $record) {
        app(GrantEntityCollaboratorAction::class)->execute(
            collaboratable: $record,
            user: $repB,
            accessLevel: CollaboratorAccessLevel::Manager,
            roleLabel: CollaboratorRoleLabel::SalesOverlay,
            grantedBy: $directorA,
        );

        expect(Gate::forUser($repB)->allows('delete', $record))->toBeTrue()
            ->and(Gate::forUser($repB)->allows('manageCollaborators', $record))->toBeTrue();
    }
    expect(Gate::forUser($repB)->allows('overrideGates', $deal))->toBeFalse();

    // Support user even with Manager collaborator cannot update/delete/moveStage because role lacks write permissions
    app(GrantEntityCollaboratorAction::class)->execute(
        collaboratable: $deal,
        user: $supportUser,
        accessLevel: CollaboratorAccessLevel::Manager,
        roleLabel: CollaboratorRoleLabel::SupportLead,
        grantedBy: $directorA,
    );
    expect(Gate::forUser($supportUser)->allows('view', $deal))->toBeTrue()
        ->and(Gate::forUser($supportUser)->allows('update', $deal))->toBeFalse()
        ->and(Gate::forUser($supportUser)->allows('moveStage', $deal))->toBeFalse()
        ->and(Gate::forUser($supportUser)->allows('delete', $deal))->toBeFalse()
        ->and(Gate::forUser($supportUser)->allows('manageCollaborators', $deal))->toBeFalse();

    // Active Admin bypasses silo & has overrideGates
    expect(Gate::forUser($admin)->allows('view', $deal))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('update', $deal))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('delete', $deal))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('moveStage', $deal))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('overrideGates', $deal))->toBeTrue();

    // Suspended or soft-deleted user denied across ALL abilities (even owner / Admin)
    $repA->update(['status' => UserStatusEnum::Suspended]);
    $admin->delete();

    expect(Gate::forUser($repA)->allows('viewAny', Deal::class))->toBeFalse()
        ->and(Gate::forUser($repA)->allows('view', $deal))->toBeFalse()
        ->and(Gate::forUser($repA)->allows('update', $deal))->toBeFalse()
        ->and(Gate::forUser($repA)->allows('moveStage', $deal))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('viewAny', Company::class))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('overrideGates', $deal))->toBeFalse();
});

it('enforces PipelinePolicy, CustomFieldDefinitionPolicy, and NotePolicy', function (): void {
    $this->seed(CrmDefaultsSeeder::class);
    $dept = Department::query()->create(['name' => 'Ops', 'slug' => 'crm-policies-ops']);

    $admin = createCrmTestUser($dept, 'EMP-NP-ADM', 'admin.np@acme.internal', RoleEnum::Admin);
    $directorA = createCrmTestUser($dept, 'EMP-NP-DIRA', 'dir.a.np@acme.internal', RoleEnum::Director);
    $repA = createCrmTestUser($dept, 'EMP-NP-REPA', 'rep.a.np@acme.internal', RoleEnum::Sales, $directorA);
    $repB = createCrmTestUser($dept, 'EMP-NP-REPB', 'rep.b.np@acme.internal', RoleEnum::Sales);
    $viewerCollab = createCrmTestUser($dept, 'EMP-NP-VIEW', 'viewer.np@acme.internal', RoleEnum::Sales);
    $editorCollab = createCrmTestUser($dept, 'EMP-NP-EDIT', 'editor.np@acme.internal', RoleEnum::Sales);

    $pipeline = Pipeline::query()->where('slug', 'new-business')->firstOrFail();
    $stage = $pipeline->stages()->orderBy('sort_order')->firstOrFail();
    $fieldDef = CustomFieldDefinition::query()->where('key', 'contract_term_months')->firstOrFail();

    // PipelinePolicy & CustomFieldDefinitionPolicy: view for all CRM roles, manage for Admin only
    foreach ([$directorA, $repA] as $nonAdmin) {
        expect(Gate::forUser($nonAdmin)->allows('viewAny', Pipeline::class))->toBeTrue()
            ->and(Gate::forUser($nonAdmin)->allows('view', $pipeline))->toBeTrue()
            ->and(Gate::forUser($nonAdmin)->allows('create', Pipeline::class))->toBeFalse()
            ->and(Gate::forUser($nonAdmin)->allows('update', $pipeline))->toBeFalse()
            ->and(Gate::forUser($nonAdmin)->allows('delete', $pipeline))->toBeFalse()
            ->and(Gate::forUser($nonAdmin)->allows('viewAny', CustomFieldDefinition::class))->toBeTrue()
            ->and(Gate::forUser($nonAdmin)->allows('view', $fieldDef))->toBeTrue()
            ->and(Gate::forUser($nonAdmin)->allows('create', CustomFieldDefinition::class))->toBeFalse()
            ->and(Gate::forUser($nonAdmin)->allows('update', $fieldDef))->toBeFalse()
            ->and(Gate::forUser($nonAdmin)->allows('delete', $fieldDef))->toBeFalse();
    }

    expect(Gate::forUser($admin)->allows('create', Pipeline::class))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('update', $pipeline))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('delete', $pipeline))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('create', CustomFieldDefinition::class))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('update', $fieldDef))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('delete', $fieldDef))->toBeTrue();

    // NotePolicy delegating to linked activity_links target entity + author + Admin
    $company = Company::query()->create(['name' => 'Note Co', 'owner_id' => $repA->id]);
    $deal = Deal::query()->create([
        'company_id' => $company->id,
        'pipeline_id' => $pipeline->id,
        'stage_id' => $stage->id,
        'owner_id' => $repA->id,
        'title' => 'Note Deal',
        'stage_entered_at' => now(),
    ]);

    $note = Note::query()->create([
        'author_id' => $repA->id,
        'body_json' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Follow up on pricing.']]]]],
        'body_text' => 'Follow up on pricing.',
    ]);

    $activity = Activity::query()->create([
        'actor_id' => $repA->id,
        'activity_type' => ActivityTypeEnum::NoteCreated,
        'source_type' => 'note',
        'source_id' => $note->id,
        'summary' => 'Added a note',
        'occurred_at' => now(),
        'created_at' => now(),
    ]);

    ActivityLink::query()->create([
        'linkable_type' => 'deal',
        'linkable_id' => $deal->id,
        'occurred_at' => $activity->occurred_at,
        'activity_id' => $activity->id,
    ]);

    app(GrantEntityCollaboratorAction::class)->execute(
        collaboratable: $deal,
        user: $viewerCollab,
        accessLevel: CollaboratorAccessLevel::Viewer,
        roleLabel: CollaboratorRoleLabel::Observer,
        grantedBy: $directorA,
    );
    app(GrantEntityCollaboratorAction::class)->execute(
        collaboratable: $deal,
        user: $editorCollab,
        accessLevel: CollaboratorAccessLevel::Editor,
        roleLabel: CollaboratorRoleLabel::SalesOverlay,
        grantedBy: $directorA,
    );

    // Author, Silo Director, and Admin can view/update/delete
    foreach ([$repA, $directorA, $admin] as $authorized) {
        expect(Gate::forUser($authorized)->allows('view', $note))->toBeTrue()
            ->and(Gate::forUser($authorized)->allows('update', $note))->toBeTrue()
            ->and(Gate::forUser($authorized)->allows('delete', $note))->toBeTrue();
    }

    // Viewer collaborator on linked deal can view note, cannot update/delete
    expect(Gate::forUser($viewerCollab)->allows('view', $note))->toBeTrue()
        ->and(Gate::forUser($viewerCollab)->allows('update', $note))->toBeFalse()
        ->and(Gate::forUser($viewerCollab)->allows('delete', $note))->toBeFalse();

    // Editor collaborator on linked deal can view/update/delete
    expect(Gate::forUser($editorCollab)->allows('view', $note))->toBeTrue()
        ->and(Gate::forUser($editorCollab)->allows('update', $note))->toBeTrue()
        ->and(Gate::forUser($editorCollab)->allows('delete', $note))->toBeTrue();

    // Cross-silo Rep B cannot view/update/delete
    expect(Gate::forUser($repB)->allows('view', $note))->toBeFalse()
        ->and(Gate::forUser($repB)->allows('update', $note))->toBeFalse()
        ->and(Gate::forUser($repB)->allows('delete', $note))->toBeFalse();

    // Suspended Admin & suspended Author denied
    $admin->update(['status' => UserStatusEnum::Suspended]);
    $repA->update(['status' => UserStatusEnum::Suspended]);

    expect(Gate::forUser($admin)->allows('view', $note))->toBeFalse()
        ->and(Gate::forUser($repA)->allows('update', $note))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('create', Pipeline::class))->toBeFalse();

    // NotePolicy::create with target model + unlinked/trashed note handling
    $admin->update(['status' => UserStatusEnum::Active]);
    $repA->update(['status' => UserStatusEnum::Active]);

    expect(Gate::forUser($viewerCollab)->allows('create', [Note::class, $deal]))->toBeFalse()
        ->and(Gate::forUser($editorCollab)->allows('create', [Note::class, $deal]))->toBeTrue();

    $unlinkedNote = Note::query()->create([
        'author_id' => $repA->id,
        'body_json' => ['type' => 'doc', 'content' => []],
        'body_text' => 'Draft private note',
    ]);
    expect(Gate::forUser($repA)->allows('view', $unlinkedNote))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('view', $unlinkedNote))->toBeTrue()
        ->and(Gate::forUser($directorA)->allows('view', $unlinkedNote))->toBeFalse();

    $note->delete();
    expect(Gate::forUser($admin)->allows('view', $note))->toBeFalse()
        ->and(Gate::forUser($repA)->allows('update', $note))->toBeFalse();

    // Soft-deleted deal cannot be updated or moved
    $deal->delete();
    expect(Gate::forUser($admin)->allows('update', $deal))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('moveStage', $deal))->toBeFalse();
});
