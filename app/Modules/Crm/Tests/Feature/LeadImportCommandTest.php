<?php

use App\Modules\Core\Rbac\Enums\RoleEnum;
use App\Modules\Core\Users\Actions\RebuildUserHierarchyClosureAction;
use App\Modules\Core\Users\Enums\UserStatusEnum;
use App\Modules\Core\Users\Models\Department;
use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Companies\Enums\CompanyLifecycleStageEnum;
use App\Modules\Crm\Companies\Models\Company;
use App\Modules\Crm\Contacts\Models\Contact;
use App\Modules\Crm\Contacts\Models\ContactEmail;
use App\Modules\Crm\Deals\Models\Deal;
use App\Modules\Crm\Leads\Enums\LeadSourceEnum;
use App\Modules\Crm\Leads\Enums\LeadStatusEnum;
use App\Modules\Crm\Leads\Models\Lead;
use App\Modules\Crm\Timeline\Enums\ActivityTypeEnum;
use App\Modules\Crm\Timeline\Models\Activity;
use Database\Seeders\CrmDefaultsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

function createLeadImportTestUser(
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

function writeTempLeadBatchFile(array $payload): string
{
    $dir = storage_path('framework/testing');
    if (! is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $path = $dir.'/lead-import-'.Str::uuid()->toString().'.json';
    file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    return $path;
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

it('performs dry-run validation and silo-scoped duplicate detection without writing DB rows', function (): void {
    $dept = Department::query()->create([
        'name' => 'Lead Import DryRun',
        'slug' => 'lead-dry-'.Str::lower(Str::random(4)),
    ]);

    $repA = createLeadImportTestUser($dept, 'IMP-A1', 'imp-a1@example.com', RoleEnum::Sales);
    $repB = createLeadImportTestUser($dept, 'IMP-B1', 'imp-b1@example.com', RoleEnum::Sales);

    // Visible records for repA
    Lead::query()->create([
        'owner_id' => $repA->id,
        'first_name' => 'Existing',
        'last_name' => 'Lead',
        'email' => 'dup-lead@northstar.example',
        'company_name' => 'Northstar Robotics',
        'status' => LeadStatusEnum::Working,
        'source' => LeadSourceEnum::Outbound,
    ]);

    $companyA = Company::query()->create([
        'name' => 'Cobalt Cloud',
        'domain' => 'cobaltcloud.example',
        'lifecycle_stage' => CompanyLifecycleStageEnum::Prospect,
        'owner_id' => $repA->id,
    ]);

    $contactA = Contact::query()->create([
        'company_id' => $companyA->id,
        'owner_id' => $repA->id,
        'first_name' => 'Jordan',
        'last_name' => 'Contact',
        'primary_email' => 'dup-contact@other.example',
    ]);
    ContactEmail::query()->create([
        'contact_id' => $contactA->id,
        'email' => 'dup-contact@other.example',
        'is_primary' => true,
    ]);

    // Sibling repB's private lead MUST NOT cause duplicate flag for repA (prevents silo existence oracle)
    Lead::query()->create([
        'owner_id' => $repB->id,
        'first_name' => 'Private',
        'last_name' => 'OtherSilo',
        'email' => 'private-silo@external.example',
        'company_name' => 'External Corp',
        'status' => LeadStatusEnum::New,
        'source' => LeadSourceEnum::Outbound,
    ]);

    $batchFile = writeTempLeadBatchFile([
        [
            'first_name' => 'Clean',
            'last_name' => 'Prospect',
            'email' => 'CLEAN@FRESH-PROSPECT.EXAMPLE',
            'company_name' => 'Fresh Prospect Inc',
            'source' => 'outbound',
            'estimated_value_cents' => 2500000,
        ],
        [
            'first_name' => 'OtherSilo',
            'last_name' => 'Allowed',
            'email' => 'private-silo@external.example',
            'company_name' => 'External Corp',
            'source' => 'outbound',
        ],
        [
            'first_name' => 'Dup',
            'last_name' => 'Lead',
            'email' => 'dup-lead@northstar.example',
            'company_name' => 'Northstar Robotics',
        ],
        [
            'first_name' => 'Dup',
            'last_name' => 'Contact',
            'email' => 'dup-contact@other.example',
            'company_name' => 'Other Co',
        ],
        [
            'first_name' => 'Dup',
            'last_name' => 'Domain',
            'website' => 'https://www.cobaltcloud.example/about',
            'company_name' => 'Cobalt Cloud',
        ],
        [
            'first_name' => 'IntraBatch',
            'last_name' => 'Dup',
            'email' => 'clean@fresh-prospect.example',
            'company_name' => 'Another Co',
        ],
        [
            'first_name' => 'Missing',
            'last_name' => 'Channel',
            'company_name' => 'No Reachability LLC',
        ],
        [
            'first_name' => 'PreConverted',
            'last_name' => 'Blocked',
            'email' => 'preconverted@blocked.example',
            'status' => 'converted',
        ],
    ]);

    try {
        $exitCode = Artisan::call('crm:leads:import', [
            'file' => $batchFile,
            '--owner' => 'imp-a1@example.com',
            '--dry-run' => true,
            '--json' => true,
        ]);

        expect($exitCode)->toBe(0);
        $output = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        expect($output['dry_run'])->toBeTrue();
        expect($output['total_rows'])->toBe(8);
        expect($output['ready_count'])->toBe(2);
        expect($output['inserted_count'])->toBe(0);
        expect($output['duplicate_count'])->toBe(4);
        expect($output['invalid_count'])->toBe(2);

        // Dry run wrote zero new rows
        expect(Lead::query()->count())->toBe(2);
        expect(Company::query()->count())->toBe(1);
        expect(Contact::query()->count())->toBe(1);
        expect(Deal::query()->count())->toBe(0);
    } finally {
        @unlink($batchFile);
    }
});

it('commits approved leads strictly with status=new and never creates companies, contacts, or deals', function (): void {
    $dept = Department::query()->create([
        'name' => 'Lead Import Commit',
        'slug' => 'lead-commit-'.Str::lower(Str::random(4)),
    ]);

    $rep = createLeadImportTestUser($dept, 'IMP-C1', 'imp-c1@example.com', RoleEnum::Sales);

    $batchFile = writeTempLeadBatchFile([
        'leads' => [
            [
                'first_name' => 'Maya',
                'last_name' => 'Lin',
                'email' => 'MAYA@APEX.EXAMPLE',
                'job_title' => 'COO',
                'company_name' => 'Apex Industrial',
                'website' => 'https://apex.example',
                'source' => 'referral',
                'estimated_value_cents' => 4200000,
                'currency' => 'usd',
            ],
            [
                'first_name' => 'NoContact',
                'last_name' => 'InvalidRow',
                'company_name' => 'Invalid Co',
            ],
        ],
    ]);

    try {
        $exitCode = Artisan::call('crm:leads:import', [
            'file' => $batchFile,
            '--owner' => 'imp-c1@example.com',
            '--commit' => true,
            '--json' => true,
        ]);

        expect($exitCode)->toBe(0);
        $output = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        expect($output['dry_run'])->toBeFalse();
        expect($output['inserted_count'])->toBe(1);
        expect($output['invalid_count'])->toBe(1);

        $leadId = $output['rows'][0]['lead_id'];
        $lead = Lead::query()->findOrFail($leadId);

        expect($lead->status)->toBe(LeadStatusEnum::New);
        expect($lead->source)->toBe(LeadSourceEnum::Referral);
        expect($lead->email)->toBe('maya@apex.example');
        expect($lead->currency)->toBe('USD');
        expect((string) $lead->owner_id)->toBe((string) $rep->id);
        expect($lead->converted_at)->toBeNull();

        // Must never touch companies, contacts, or deals
        expect(Company::query()->count())->toBe(0);
        expect(Contact::query()->count())->toBe(0);
        expect(Deal::query()->count())->toBe(0);

        expect(
            Activity::query()
                ->where('activity_type', ActivityTypeEnum::LeadCreated)
                ->where('source_id', $lead->id)
                ->exists()
        )->toBeTrue();
    } finally {
        @unlink($batchFile);
    }
});

it('fails closed for unauthorized roles, suspended users, and conflicting flags', function (): void {
    $dept = Department::query()->create([
        'name' => 'Lead Import Auth',
        'slug' => 'lead-auth-'.Str::lower(Str::random(4)),
    ]);

    createLeadImportTestUser($dept, 'IMP-SUP', 'support-no-create@example.com', RoleEnum::Support);
    createLeadImportTestUser($dept, 'IMP-SUSP', 'suspended-rep@example.com', RoleEnum::Sales, null, UserStatusEnum::Suspended);

    $batchFile = writeTempLeadBatchFile([
        [
            'first_name' => 'Blocked',
            'last_name' => 'User',
            'email' => 'blocked@example.com',
            'company_name' => 'Blocked Corp',
        ],
    ]);

    try {
        expect(Artisan::call('crm:leads:import', [
            'file' => $batchFile,
            '--owner' => 'support-no-create@example.com',
            '--commit' => true,
        ]))->toBe(1);

        expect(Artisan::call('crm:leads:import', [
            'file' => $batchFile,
            '--owner' => 'suspended-rep@example.com',
            '--commit' => true,
        ]))->toBe(1);

        expect(Artisan::call('crm:leads:import', [
            'file' => $batchFile,
            '--owner' => 'support-no-create@example.com',
            '--dry-run' => true,
            '--commit' => true,
        ]))->toBe(1);

        expect(Lead::query()->count())->toBe(0);
    } finally {
        @unlink($batchFile);
    }
});
