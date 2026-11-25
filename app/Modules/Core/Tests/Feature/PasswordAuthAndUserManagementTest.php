<?php

use App\Modules\Core\Rbac\Enums\RoleEnum;
use App\Modules\Core\Users\Enums\UserStatusEnum;
use App\Modules\Core\Users\Models\Department;
use App\Modules\Core\Users\Models\User;
use App\Modules\Core\Users\Models\UserHierarchyClosure;
use App\Support\Silo\VisibleScopeResolver;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\Hash;

it('seeds an active superadmin user with unrestricted silo visibility and valid password hash', function (): void {
    $this->seed(DatabaseSeeder::class);

    $superadmin = User::query()->where('email', 'superadmin@example.com')->first();

    expect($superadmin)->not->toBeNull()
        ->and($superadmin->status)->toBe(UserStatusEnum::Active)
        ->and($superadmin->hasRole(RoleEnum::Superadmin->value))->toBeTrue()
        ->and(Hash::check('SuperAdmin123!', (string) $superadmin->password))->toBeTrue();

    $scope = app(VisibleScopeResolver::class)->for($superadmin);
    expect($scope->isUnrestrictedAdmin)->toBeTrue();
});

it('redirects guests from /app to /login, allows superadmin password login, and logs out cleanly', function (): void {
    $this->seed(DatabaseSeeder::class);

    $this->get('/app')->assertRedirect('/login');
    $this->get('/login')->assertOk();

    $this->post('/login', [
        'email' => 'SUPERADMIN@example.com',
        'password' => 'SuperAdmin123!',
        'remember' => true,
    ])->assertRedirect('/app');

    $this->assertAuthenticated();
    expect(session('auth.authenticated_at'))->toBeInt();

    $this->get('/app')->assertOk();

    $this->post('/logout')->assertRedirect('/login');
    $this->assertGuest();
});

it('rejects invalid credentials and inactive accounts on POST /login', function (): void {
    $this->seed(RolesPermissionsSeeder::class);

    $active = User::factory()->create([
        'email' => 'active.user@company.internal',
        'password' => 'CorrectPass1!',
        'status' => UserStatusEnum::Active,
    ]);

    $suspended = User::factory()->suspended()->create([
        'email' => 'suspended.user@company.internal',
        'password' => 'CorrectPass1!',
    ]);

    $this->from('/login')
        ->post('/login', [
            'email' => $active->email,
            'password' => 'WrongPassword1!',
        ])
        ->assertRedirect('/login')
        ->assertSessionHasErrors('email');
    $this->assertGuest();

    $this->from('/login')
        ->post('/login', [
            'email' => $suspended->email,
            'password' => 'CorrectPass1!',
        ])
        ->assertRedirect('/login')
        ->assertSessionHasErrors('email');
    $this->assertGuest();
});

it('forbids non-superadmin users (including regular admins) from accessing or creating users', function (RoleEnum $role): void {
    $this->seed(RolesPermissionsSeeder::class);
    $dept = Department::query()->firstOrCreate(['slug' => 'general'], ['name' => 'General']);

    $user = User::factory()->create([
        'department_id' => $dept->id,
        'status' => UserStatusEnum::Active,
    ]);
    $user->assignRole($role->value);

    $this->actingAs($user)
        ->get('/app/admin/users')
        ->assertForbidden();

    $this->actingAs($user)
        ->post('/app/admin/users', [
            'name' => 'Blocked Provision',
            'email' => 'blocked@company.internal',
            'password' => 'StrongPass1!',
            'role' => RoleEnum::Sales->value,
            'department_id' => $dept->id,
        ])
        ->assertForbidden();

    expect(User::query()->where('email', 'blocked@company.internal')->exists())->toBeFalse();
})->with([
    'regular admin' => [RoleEnum::Admin],
    'sales rep' => [RoleEnum::Sales],
    'operations manager' => [RoleEnum::OperationsManager],
]);

it('allows superadmin to view users, create a new user with role and department, and sign in as the new user', function (): void {
    $this->seed(DatabaseSeeder::class);

    $superadmin = User::query()->where('email', 'superadmin@example.com')->firstOrFail();
    $dept = Department::query()->create(['name' => 'Revenue Ops', 'slug' => 'revenue-ops']);

    $this->actingAs($superadmin)
        ->get('/app/admin/users')
        ->assertOk();

    $this->actingAs($superadmin)
        ->post('/app/admin/users', [
            'name' => 'Taylor Reed',
            'email' => 'Taylor.Reed@company.internal',
            'password' => 'NewUserPass1!',
            'role' => RoleEnum::Sales->value,
            'department_id' => $dept->id,
            'job_title' => 'Account Executive',
        ])
        ->assertRedirect('/app/admin/users')
        ->assertSessionHas('success');

    $created = User::query()->where('email', 'taylor.reed@company.internal')->first();
    expect($created)->not->toBeNull()
        ->and($created->name)->toBe('Taylor Reed')
        ->and($created->department_id)->toBe($dept->id)
        ->and($created->job_title)->toBe('Account Executive')
        ->and($created->hasRole(RoleEnum::Sales->value))->toBeTrue()
        ->and(UserHierarchyClosure::query()
            ->where('ancestor_id', $created->id)
            ->where('descendant_id', $created->id)
            ->exists())->toBeTrue();

    // Duplicate email rejected cleanly
    $this->actingAs($superadmin)
        ->from('/app/admin/users')
        ->post('/app/admin/users', [
            'name' => 'Duplicate User',
            'email' => 'taylor.reed@company.internal',
            'password' => 'NewUserPass1!',
            'role' => RoleEnum::Sales->value,
        ])
        ->assertRedirect('/app/admin/users')
        ->assertSessionHasErrors('email');

    // Newly created user can sign in and access /app
    $this->post('/logout')->assertRedirect('/login');

    $this->post('/login', [
        'email' => 'taylor.reed@company.internal',
        'password' => 'NewUserPass1!',
    ])->assertRedirect('/app');

    $this->assertAuthenticatedAs($created);
    $this->get('/app')->assertOk();
});
