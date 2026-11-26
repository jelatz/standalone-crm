<?php

use App\Modules\Core\Rbac\Enums\RoleEnum;
use App\Modules\Core\Users\Actions\RebuildUserHierarchyClosureAction;
use App\Modules\Core\Users\Enums\UserStatusEnum;
use App\Modules\Core\Users\Models\Department;
use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Companies\Enums\CompanyLifecycleStageEnum;
use App\Modules\Crm\Companies\Models\Company;
use App\Modules\Crm\Contacts\Models\Contact;
use App\Modules\Crm\Deals\Models\Deal;
use App\Modules\Crm\Leads\Enums\LeadSourceEnum;
use App\Modules\Crm\Leads\Enums\LeadStatusEnum;
use App\Modules\Crm\Leads\Models\Lead;
use App\Modules\Crm\Timeline\Enums\ActivityTypeEnum;
use App\Modules\Crm\Timeline\Models\Activity;
use Database\Seeders\CrmDefaultsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\PermissionRegistrar;

function createSpreadsheetImportUser(
    Department $department,
    string $code,
    string $email,
    RoleEnum $role,
    UserStatusEnum $status = UserStatusEnum::Active,
): User {
    $user = User::query()->create([
        'employee_code' => $code,
        'name' => $code,
        'email' => $email,
        'department_id' => $department->id,
        'status' => $status,
    ]);

    $user->assignRole($role->value);
    app(RebuildUserHierarchyClosureAction::class)->execute($user);

    return $user;
}

function makeSpreadsheetUpload(string $format, array $rows, string $filename): UploadedFile
{
    $dir = storage_path('framework/testing');
    if (! is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $path = $dir.'/'.Str::uuid()->toString().'-'.$filename;

    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();

    foreach ($rows as $rIdx => $row) {
        foreach (array_values($row) as $cIdx => $value) {
            $sheet->setCellValue([$cIdx + 1, $rIdx + 1], $value);
        }
    }

    if ($format === 'xlsx') {
        $writer = new Xlsx($spreadsheet);
        $mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    } else {
        $writer = new Xls($spreadsheet);
        $mime = 'application/vnd.ms-excel';
    }

    $writer->save($path);
    $spreadsheet->disconnectWorksheets();
    unset($spreadsheet);

    return new UploadedFile($path, $filename, $mime, null, true);
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

it('previews CSV spreadsheets with Full Name split, amount normalization, and silo duplicate checks without writing DB rows', function (): void {
    $dept = Department::query()->create([
        'name' => 'Spreadsheet Preview Dept',
        'slug' => 'sheet-prev-'.Str::lower(Str::random(4)),
    ]);

    $rep = createSpreadsheetImportUser($dept, 'SH-P1', 'sh-p1@example.com', RoleEnum::Sales);

    Lead::query()->create([
        'owner_id' => $rep->id,
        'first_name' => 'Existing',
        'last_name' => 'Prospect',
        'email' => 'dup@existing-lead.example',
        'company_name' => 'Existing Lead Co',
        'status' => LeadStatusEnum::New,
        'source' => LeadSourceEnum::Outbound,
    ]);

    $csvContent = implode("\n", [
        'Full Name,Work Email,Company,Est Value,Source',
        'Kieran Gallagher,k.gallagher@vanguardauto.example,Vanguard Automation,"$45,000",Web',
        'Existing Prospect,dup@existing-lead.example,Existing Lead Co,$10000,Outbound',
        'Missing Channel,,No Channel LLC,$5000,Referral',
    ]);

    $csvUpload = UploadedFile::fake()->createWithContent('leads.csv', $csvContent);

    $response = $this->actingAs($rep)
        ->post('/api/internal/crm/leads/import/preview', [
            'file' => $csvUpload,
        ], ['Accept' => 'application/json'])
        ->assertOk();

    $response->assertJsonPath('total_rows', 3)
        ->assertJsonPath('ready_count', 1)
        ->assertJsonPath('duplicate_count', 1)
        ->assertJsonPath('invalid_count', 1)
        ->assertJsonPath('detected_mapping.full_name', 'Full Name')
        ->assertJsonPath('detected_mapping.email', 'Work Email')
        ->assertJsonPath('detected_mapping.estimated_value', 'Est Value')
        ->assertJsonPath('rows.0.outcome', 'ready')
        ->assertJsonPath('rows.0.payload.first_name', 'Kieran')
        ->assertJsonPath('rows.0.payload.last_name', 'Gallagher')
        ->assertJsonPath('rows.0.payload.source', LeadSourceEnum::InboundWeb->value)
        ->assertJsonPath('rows.0.payload.estimated_value_cents', 4500000)
        ->assertJsonPath('rows.1.outcome', 'duplicate')
        ->assertJsonPath('rows.2.outcome', 'invalid');

    // Dry-run preview must not insert anything
    expect(Lead::query()->count())->toBe(1);
    expect(Company::query()->count())->toBe(0);
    expect(Contact::query()->count())->toBe(0);
    expect(Deal::query()->count())->toBe(0);
});

it('parses real XLS and XLSX workbooks, honors custom column_mapping, and commits approved rows strictly as status=new', function (): void {
    $dept = Department::query()->create([
        'name' => 'Excel Import Dept',
        'slug' => 'excel-imp-'.Str::lower(Str::random(4)),
    ]);

    $rep = createSpreadsheetImportUser($dept, 'SH-E1', 'sh-e1@example.com', RoleEnum::Sales);

    // 1. Real .xlsx workbook
    $xlsxUpload = makeSpreadsheetUpload('xlsx', [
        ['Given', 'Surname', 'Contact Mail', 'Org', 'Site', 'Budget', 'Channel'],
        ['Amara', 'Okafor', 'amara@solsticegrid.example', 'Solstice Grid', 'https://solsticegrid.example', '$125,000.50', 'Partner'],
    ], 'q4-leads.xlsx');

    $previewXlsx = $this->actingAs($rep)
        ->post('/api/internal/crm/leads/import/preview', [
            'file' => $xlsxUpload,
            'column_mapping' => json_encode([
                'first_name' => 'Given',
                'last_name' => 'Surname',
                'email' => 'Contact Mail',
                'company_name' => 'Org',
                'website' => 'Site',
                'estimated_value' => 'Budget',
                'source' => 'Channel',
            ], JSON_THROW_ON_ERROR),
        ], ['Accept' => 'application/json'])
        ->assertOk();

    $previewXlsx->assertJsonPath('ready_count', 1)
        ->assertJsonPath('rows.0.payload.first_name', 'Amara')
        ->assertJsonPath('rows.0.payload.company_name', 'Solstice Grid')
        ->assertJsonPath('rows.0.payload.estimated_value_cents', 12500050)
        ->assertJsonPath('rows.0.payload.source', LeadSourceEnum::Partner->value);

    // 2. Real legacy .xls workbook
    $xlsUpload = makeSpreadsheetUpload('xls', [
        ['First Name', 'Last Name', 'Email', 'Company', 'Website', 'Source'],
        ['Siddharth', 'Rao', 'srao@beaconbio.example', 'Beacon BioSystems', 'https://beaconbio.example', 'Event'],
    ], 'legacy-leads.xls');

    $previewXls = $this->actingAs($rep)
        ->post('/api/internal/crm/leads/import/preview', [
            'file' => $xlsUpload,
        ], ['Accept' => 'application/json'])
        ->assertOk();

    $previewXls->assertJsonPath('ready_count', 1)
        ->assertJsonPath('rows.0.payload.first_name', 'Siddharth')
        ->assertJsonPath('rows.0.payload.source', LeadSourceEnum::Event->value);

    // 3. Commit approved rows strictly into leads as status=new (never deals/companies/contacts)
    $approvedRows = [
        $previewXlsx->json('rows.0.payload'),
        $previewXls->json('rows.0.payload'),
    ];

    $commitResp = $this->actingAs($rep)
        ->postJson('/api/internal/crm/leads/import/commit', [
            'rows' => $approvedRows,
        ])
        ->assertCreated();

    $commitResp->assertJsonPath('inserted_count', 2)
        ->assertJsonPath('duplicate_count', 0)
        ->assertJsonPath('invalid_count', 0);

    expect(Lead::query()->count())->toBe(2);
    expect(Lead::query()->where('status', LeadStatusEnum::New->value)->count())->toBe(2);
    expect(Company::query()->count())->toBe(0);
    expect(Contact::query()->count())->toBe(0);
    expect(Deal::query()->count())->toBe(0);
    expect(Activity::query()->where('activity_type', ActivityTypeEnum::LeadCreated)->count())->toBe(2);
});

it('serves downloadable CSV template and rejects unauthorized users and disguised or oversized spreadsheets', function (): void {
    $dept = Department::query()->create([
        'name' => 'Spreadsheet Auth Dept',
        'slug' => 'sheet-auth-'.Str::lower(Str::random(4)),
    ]);

    $rep = createSpreadsheetImportUser($dept, 'SH-A1', 'sh-a1@example.com', RoleEnum::Sales);
    $support = createSpreadsheetImportUser($dept, 'SH-SUP', 'sh-sup@example.com', RoleEnum::Support);

    // Template download works for Sales, forbidden for Support
    $this->actingAs($rep)
        ->get('/api/internal/crm/leads/import/template')
        ->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8')
        ->assertHeader('x-content-type-options', 'nosniff');

    $this->actingAs($support)
        ->get('/api/internal/crm/leads/import/template')
        ->assertForbidden();

    // Unauthorized role rejected on preview & commit
    $csvUpload = UploadedFile::fake()->createWithContent('leads.csv', "first_name,last_name,email\nJane,Doe,jane@example.com\n");
    $this->actingAs($support)
        ->post('/api/internal/crm/leads/import/preview', ['file' => $csvUpload], ['Accept' => 'application/json'])
        ->assertForbidden();

    $this->actingAs($support)
        ->postJson('/api/internal/crm/leads/import/commit', [
            'rows' => [[
                'first_name' => 'Jane',
                'last_name' => 'Doe',
                'email' => 'jane@example.com',
                'source' => 'outbound',
                'currency' => 'USD',
            ]],
        ])
        ->assertForbidden();

    // HTML disguised as .xls or .csv must fail closed with 422
    $disguisedHtml = UploadedFile::fake()->createWithContent('evil.xls', '<html><body><table><tr><td>hello</td></tr></table></body></html>');
    $this->actingAs($rep)
        ->post('/api/internal/crm/leads/import/preview', ['file' => $disguisedHtml], ['Accept' => 'application/json'])
        ->assertUnprocessable();

    $disguisedHtmlCsv = UploadedFile::fake()->createWithContent('evil.csv', "<!doctype html>\n<html><body>bad</body></html>\n");
    $this->actingAs($rep)
        ->post('/api/internal/crm/leads/import/preview', ['file' => $disguisedHtmlCsv], ['Accept' => 'application/json'])
        ->assertUnprocessable();

    // Disallowed extension rejected with 422
    $phpUpload = UploadedFile::fake()->createWithContent('shell.php', '<?php echo 1; ?>');
    $this->actingAs($rep)
        ->post('/api/internal/crm/leads/import/preview', ['file' => $phpUpload], ['Accept' => 'application/json'])
        ->assertUnprocessable();

    // Unsafe website scheme & integer overflow amount rejected cleanly; formula prefixes defused
    $edgeCsv = implode("\n", [
        'first_name,last_name,email,phone,website,estimated_value',
        '=Bad,User,xss-site@example.com,+1-415-555-0199,javascript:alert(1),$5000',
        '+HYPERLINK("http://evil"),Overflow,bigmoney@example.com,+1-415-555-0188,https://safe.example,100000000000000000000',
        'Safe,Phone,safephone@example.com,+1-415-555-0133,https://safe.example,$1200',
    ]);
    $edgeUpload = UploadedFile::fake()->createWithContent('edge.csv', $edgeCsv);
    $edgePreview = $this->actingAs($rep)
        ->post('/api/internal/crm/leads/import/preview', ['file' => $edgeUpload], ['Accept' => 'application/json'])
        ->assertOk();

    $edgePreview->assertJsonPath('rows.0.outcome', 'invalid')
        ->assertJsonPath('rows.1.outcome', 'invalid')
        ->assertJsonPath('rows.2.outcome', 'ready')
        ->assertJsonPath('rows.2.payload.phone', '+1-415-555-0133');

    // Direct commit with javascript: website must fail validation cleanly with 422
    $this->actingAs($rep)
        ->postJson('/api/internal/crm/leads/import/commit', [
            'rows' => [[
                'first_name' => 'Xss',
                'last_name' => 'Site',
                'website' => 'javascript:alert(1)',
                'source' => 'outbound',
                'currency' => 'USD',
            ]],
        ])
        ->assertUnprocessable();

    // Re-committing same row skips duplicate cleanly and returns 200 with inserted_count=0
    $safeRow = $edgePreview->json('rows.2.payload');
    $this->actingAs($rep)
        ->postJson('/api/internal/crm/leads/import/commit', ['rows' => [$safeRow]])
        ->assertCreated()
        ->assertJsonPath('inserted_count', 1);

    $this->actingAs($rep)
        ->postJson('/api/internal/crm/leads/import/commit', ['rows' => [$safeRow]])
        ->assertOk()
        ->assertJsonPath('inserted_count', 0)
        ->assertJsonPath('duplicate_count', 1);
});
