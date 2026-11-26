<?php

use App\Modules\Core\Rbac\Enums\RoleEnum;
use App\Modules\Core\Users\Enums\UserStatusEnum;
use App\Modules\Core\Users\Models\Department;
use App\Modules\Core\Users\Models\User;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->tempSopsRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'crm-sops-test-' . Str::uuid();
    File::ensureDirectoryExists($this->tempSopsRoot . '/leads');
    File::ensureDirectoryExists($this->tempSopsRoot . '/contacts');
    File::ensureDirectoryExists($this->tempSopsRoot . '/companies');
    File::ensureDirectoryExists($this->tempSopsRoot . '/deals');

    $seedContent = "# Standard Operating Procedure: Lead Intake\n\n## 1. Purpose\nInitial intake rules.\n";
    file_put_contents($this->tempSopsRoot . '/leads/lead-intake-and-qualification.md', $seedContent);
    file_put_contents($this->tempSopsRoot . '/leads/.gitkeep', '');

    config()->set('sops.root_path', $this->tempSopsRoot);
});

afterEach(function (): void {
    if (isset($this->tempSopsRoot) && is_string($this->tempSopsRoot) && is_dir($this->tempSopsRoot)) {
        File::deleteDirectory($this->tempSopsRoot);
    }
});

function makeUserWithRole(?RoleEnum $role, UserStatusEnum $status = UserStatusEnum::Active): User
{
    $dept = Department::query()->firstOrCreate(['slug' => 'general'], ['name' => 'General']);

    $user = User::factory()->create([
        'department_id' => $dept->id,
        'status' => $status,
    ]);

    if ($role !== null) {
        $user->assignRole($role->value);
    }

    return $user;
}

it('allows superadmin to view the SOP management page and read existing SOP markdown files', function (): void {
    $this->seed(RolesPermissionsSeeder::class);
    $superadmin = makeUserWithRole(RoleEnum::Superadmin);

    $this->actingAs($superadmin)
        ->get('/app/admin/sops')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('core/pages/SopsPage')
            ->where('moduleTitle', 'SOPs')
            ->has('modules', 4)
            ->where('modules.0.module', 'leads')
            ->where('modules.0.sops.0.slug', 'lead-intake-and-qualification')
            ->where('initialDocument.relative_path', 'context/sops/leads/lead-intake-and-qualification.md')
            ->where('initialDocument.title', 'Standard Operating Procedure: Lead Intake')
        );

    $this->actingAs($superadmin)
        ->getJson('/api/internal/admin/sops/leads/lead-intake-and-qualification')
        ->assertOk()
        ->assertJsonPath('module', 'leads')
        ->assertJsonPath('slug', 'lead-intake-and-qualification')
        ->assertJsonPath('content', "# Standard Operating Procedure: Lead Intake\n\n## 1. Purpose\nInitial intake rules.\n");
});

it('allows superadmin to create an SOP in an existing module and in a brand-new module on disk', function (): void {
    $this->seed(RolesPermissionsSeeder::class);
    $superadmin = makeUserWithRole(RoleEnum::Superadmin);

    $dealsContent = "# Enterprise Deal Qualification\n\nVerify executive sponsor before Proposal stage.\n";

    $this->actingAs($superadmin)
        ->postJson('/api/internal/admin/sops', [
            'module' => 'deals',
            'name' => 'Enterprise Qualification',
            'content' => $dealsContent,
        ])
        ->assertCreated()
        ->assertJsonPath('module', 'deals')
        ->assertJsonPath('slug', 'enterprise-qualification')
        ->assertJsonPath('relative_path', 'context/sops/deals/enterprise-qualification.md')
        ->assertJsonPath('title', 'Enterprise Deal Qualification');

    expect(is_file($this->tempSopsRoot . '/deals/enterprise-qualification.md'))->toBeTrue()
        ->and(file_get_contents($this->tempSopsRoot . '/deals/enterprise-qualification.md'))->toBe($dealsContent);

    $renewalContent = "# Renewal Playbook\n\nStart renewal outreach 90 days before contract end.\n";

    $this->actingAs($superadmin)
        ->postJson('/api/internal/admin/sops', [
            'module' => 'Renewals & Expansion',
            'name' => 'Renewal Playbook',
            'content' => $renewalContent,
        ])
        ->assertCreated()
        ->assertJsonPath('module', 'renewals-expansion')
        ->assertJsonPath('slug', 'renewal-playbook')
        ->assertJsonPath('relative_path', 'context/sops/renewals-expansion/renewal-playbook.md');

    expect(is_dir($this->tempSopsRoot . '/renewals-expansion'))->toBeTrue()
        ->and(is_file($this->tempSopsRoot . '/renewals-expansion/renewal-playbook.md'))->toBeTrue()
        ->and(file_get_contents($this->tempSopsRoot . '/renewals-expansion/renewal-playbook.md'))->toBe($renewalContent);

    $this->actingAs($superadmin)
        ->get('/app/admin/sops')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('core/pages/SopsPage')
            ->has('modules', 5)
            ->where('modules.4.module', 'renewals-expansion')
            ->where('modules.4.sops.0.slug', 'renewal-playbook')
        );
});

it('allows superadmin to update an existing SOP and enforces optimistic sha256 concurrency and duplicate checks', function (): void {
    $this->seed(RolesPermissionsSeeder::class);
    $superadmin = makeUserWithRole(RoleEnum::Superadmin);

    $initial = $this->actingAs($superadmin)
        ->getJson('/api/internal/admin/sops/leads/lead-intake-and-qualification')
        ->assertOk()
        ->json();

    $updatedContent = "# Lead Intake Updated\n\n## 1. Purpose\nUpdated qualification gates.\n";

    $updatedResponse = $this->actingAs($superadmin)
        ->putJson('/api/internal/admin/sops/leads/lead-intake-and-qualification', [
            'content' => $updatedContent,
            'expected_sha256' => $initial['sha256'],
        ])
        ->assertOk()
        ->assertJsonPath('title', 'Lead Intake Updated')
        ->assertJsonPath('content', $updatedContent)
        ->json();

    expect($updatedResponse['sha256'])->not->toBe($initial['sha256'])
        ->and(file_get_contents($this->tempSopsRoot . '/leads/lead-intake-and-qualification.md'))->toBe($updatedContent);

    // Stale sha256 returns 409 Conflict without overwriting disk
    $this->actingAs($superadmin)
        ->putJson('/api/internal/admin/sops/leads/lead-intake-and-qualification', [
            'content' => "# Stale Overwrite\n",
            'expected_sha256' => $initial['sha256'],
        ])
        ->assertStatus(409)
        ->assertJsonPath('current_sha256', $updatedResponse['sha256'])
        ->assertJsonPath('document.content', $updatedContent);

    expect(file_get_contents($this->tempSopsRoot . '/leads/lead-intake-and-qualification.md'))->toBe($updatedContent);

    // Duplicate SOP creation returns 422 on name
    $this->actingAs($superadmin)
        ->postJson('/api/internal/admin/sops', [
            'module' => 'leads',
            'name' => 'lead-intake-and-qualification',
            'content' => '# Duplicate',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('name');

    // Empty content creation and update succeed (ConvertEmptyStringsToNull compatible)
    $emptyCreated = $this->actingAs($superadmin)
        ->postJson('/api/internal/admin/sops', [
            'module' => 'contacts',
            'name' => 'Blank SOP',
            'content' => '',
        ])
        ->assertCreated()
        ->assertJsonPath('content', '')
        ->json();

    $this->actingAs($superadmin)
        ->putJson('/api/internal/admin/sops/contacts/blank-sop', [
            'content' => '',
            'expected_sha256' => $emptyCreated['sha256'],
        ])
        ->assertOk()
        ->assertJsonPath('content', '');
});

it('forbids non-superadmin and zero-role users across all SOP routes', function (?RoleEnum $role): void {
    $this->seed(RolesPermissionsSeeder::class);
    $actor = makeUserWithRole($role);

    $this->actingAs($actor)->get('/app/admin/sops')->assertForbidden();
    $this->actingAs($actor)->getJson('/api/internal/admin/sops/leads/lead-intake-and-qualification')->assertForbidden();
    $this->actingAs($actor)->postJson('/api/internal/admin/sops', [])->assertForbidden();
    $this->actingAs($actor)->putJson('/api/internal/admin/sops/leads/lead-intake-and-qualification', [])->assertForbidden();
})->with([
    'regular admin' => [RoleEnum::Admin],
    'director' => [RoleEnum::Director],
    'sales' => [RoleEnum::Sales],
    'operations manager' => [RoleEnum::OperationsManager],
    'it' => [RoleEnum::It],
    'zero role user' => [null],
]);

it('blocks suspended superadmins via gate and active-employee middleware across all SOP routes', function (): void {
    $this->seed(RolesPermissionsSeeder::class);
    $suspendedSuperadmin = makeUserWithRole(RoleEnum::Superadmin, UserStatusEnum::Suspended);

    expect(Gate::forUser($suspendedSuperadmin)->allows('admin.sops.manage'))->toBeFalse();

    $this->actingAs($suspendedSuperadmin)->get('/app/admin/sops')->assertRedirect('/login');
    $this->actingAs($suspendedSuperadmin)->getJson('/api/internal/admin/sops/leads/lead-intake-and-qualification')->assertForbidden();
    $this->actingAs($suspendedSuperadmin)->postJson('/api/internal/admin/sops', [])->assertForbidden();
    $this->actingAs($suspendedSuperadmin)->putJson('/api/internal/admin/sops/leads/lead-intake-and-qualification', [])->assertForbidden();
});

it('redirects guests to login on SOP management routes', function (): void {
    $this->get('/app/admin/sops')->assertRedirect('/login');
    $this->getJson('/api/internal/admin/sops/leads/lead-intake-and-qualification')->assertUnauthorized();
    $this->postJson('/api/internal/admin/sops', ['module' => 'leads', 'name' => 'x', 'content' => 'y'])->assertUnauthorized();
    $this->putJson('/api/internal/admin/sops/leads/lead-intake-and-qualification', [
        'content' => 'y',
        'expected_sha256' => str_repeat('a', 64),
    ])->assertUnauthorized();
});

it('rejects path traversal, dotfiles, invalid slugs, and symlinks inside SOP storage', function (): void {
    $this->seed(RolesPermissionsSeeder::class);
    $superadmin = makeUserWithRole(RoleEnum::Superadmin);

    // Path traversal / dotfile / missing reads
    $this->actingAs($superadmin)->getJson('/api/internal/admin/sops/leads/.gitkeep')->assertNotFound();
    $this->actingAs($superadmin)->getJson('/api/internal/admin/sops/leads/missing-sop')->assertNotFound();
    $this->actingAs($superadmin)->get('/api/internal/admin/sops/..%2F/etc')->assertNotFound();

    // Path traversal / invalid sequences on create
    $this->actingAs($superadmin)
        ->postJson('/api/internal/admin/sops', [
            'module' => '../escape',
            'name' => 'safe-name',
            'content' => '# Bad',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('module');

    $this->actingAs($superadmin)
        ->postJson('/api/internal/admin/sops', [
            'module' => 'leads',
            'name' => 'sub/escape',
            'content' => '# Bad',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('name');

    // Symlink file inside module directory must not be listed or readable
    $outsideFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'outside-secret-' . Str::uuid() . '.md';
    file_put_contents($outsideFile, "# Secret\nShould never be exposed.\n");
    @symlink($outsideFile, $this->tempSopsRoot . '/leads/symlinked-secret.md');

    try {
        $this->actingAs($superadmin)
            ->getJson('/api/internal/admin/sops/leads/symlinked-secret')
            ->assertNotFound();

        $this->actingAs($superadmin)
            ->get('/app/admin/sops')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('core/pages/SopsPage')
                ->has('modules.0.sops', 1)
            );
    } finally {
        @unlink($this->tempSopsRoot . '/leads/symlinked-secret.md');
        @unlink($outsideFile);
    }
});
