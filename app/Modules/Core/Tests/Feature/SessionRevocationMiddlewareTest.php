<?php

use App\Modules\Core\Auth\Actions\RevokeEmployeeSessionsAction;
use App\Modules\Core\Rbac\Actions\GrantEntityCollaboratorAction;
use App\Modules\Core\Rbac\Enums\CollaboratorAccessLevel;
use App\Modules\Core\Rbac\Enums\CollaboratorRoleLabel;
use App\Modules\Core\Rbac\Enums\RoleEnum;
use App\Modules\Core\Users\Enums\UserStatusEnum;
use App\Modules\Core\Users\Models\Department;
use App\Modules\Core\Users\Models\User;
use App\Support\Concerns\HasUuidV7;
use App\Support\Silo\HasSiloVisibility;
use App\Support\Silo\VisibleScopeResolver;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\Factory as SocialiteFactory;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteTwoUser;
use Spatie\Permission\PermissionRegistrar;

function makeRevocationSsoUser(string $email, string $id = 'google-sub-rev'): SocialiteTwoUser
{
    $socialiteUser = (new SocialiteTwoUser)->setRaw([
        'sub' => $id,
        'email' => $email,
        'email_verified' => true,
        'hd' => 'acme.com',
    ])->map([
        'id' => $id,
        'name' => 'Active Employee',
        'email' => $email,
    ]);

    $socialiteUser->setToken('access-token')->setApprovedScopes(['openid', 'email']);

    return $socialiteUser;
}

function mockGoogleSsoLogin(string $email, string $id = 'google-sub-rev'): void
{
    $mockProvider = Mockery::mock();
    $mockProvider->shouldReceive('user')->once()->andReturn(makeRevocationSsoUser($email, $id));

    $factory = Mockery::mock(SocialiteFactory::class);
    $factory->shouldReceive('driver')->with('google')->once()->andReturn($mockProvider);

    app()->instance(SocialiteFactory::class, $factory);
    Socialite::swap($factory);
}

beforeEach(function (): void {
    Cache::flush();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    Config::set('sso.allowed_google_domains', ['acme.com']);
    Config::set('sso.jit_provision_enabled', false);
    Config::set('services.google', [
        'client_id' => 'test-google-client-id',
        'client_secret' => 'test-google-client-secret',
        'redirect' => 'http://localhost/auth/google/callback',
    ]);

    $this->seed(RolesPermissionsSeeder::class);
});

afterEach(function (): void {
    Carbon::setTestNow();
    Socialite::clearResolvedInstances();
    Mockery::close();
    Cache::flush();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('revokes session and redirects to /login with sso error when active user is suspended, offboarded, or soft-deleted', function (string $action): void {
    $department = Department::query()->firstOrCreate(['slug' => 'general'], ['name' => 'General']);
    $user = User::factory()->create([
        'department_id' => $department->id,
        'email' => 'employee@acme.com',
        'status' => UserStatusEnum::Active,
    ]);

    mockGoogleSsoLogin('employee@acme.com');
    $this->get('/auth/google/callback?code=c1&state=s1')->assertRedirect('/app');
    $this->get('/app')->assertOk();

    $oldToken = session()->token();

    if ($action === 'suspend') {
        $user->update(['status' => UserStatusEnum::Suspended]);
    } elseif ($action === 'offboard') {
        $user->update(['status' => UserStatusEnum::Offboarded]);
    } elseif ($action === 'soft_delete') {
        $user->delete();
    } else {
        $user->deleted_at = now();
        $user->save();
    }

    expect(Cache::get(RevokeEmployeeSessionsAction::cacheKey((string) $user->id)))
        ->not->toBeNull();

    $this->get('/app')
        ->assertRedirect('/login')
        ->assertSessionHasErrors('sso');

    $this->assertGuest();
    expect(session()->token())->not->toBe($oldToken);
})->with([
    'suspend status' => ['suspend'],
    'offboard status' => ['offboard'],
    'eloquent soft delete()' => ['soft_delete'],
    'dirty deleted_at save()' => ['dirty_deleted_at'],
]);

it('returns 403 JSON on JSON and /broadcasting/auth requests for inactive or revoked employee sessions', function (): void {
    Config::set('broadcasting.default', 'reverb');
    Config::set('broadcasting.connections.reverb', [
        'driver' => 'reverb',
        'key' => 'test-reverb-key',
        'secret' => 'test-reverb-secret',
        'app_id' => 'test-reverb-app',
        'options' => [
            'host' => '127.0.0.1',
            'port' => 8080,
            'scheme' => 'http',
            'useTLS' => false,
        ],
    ]);
    Broadcast::forgetDrivers();
    require base_path('routes/channels.php');

    $user = User::factory()->create([
        'email' => 'reverb.revoked@acme.com',
        'status' => UserStatusEnum::Active,
    ]);

    $this->actingAs($user)
        ->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-user.{$user->id}",
        ])
        ->assertOk();

    $user->update(['status' => UserStatusEnum::Suspended]);

    $this->actingAs($user)
        ->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-user.{$user->id}",
        ])
        ->assertForbidden()
        ->assertExactJson([
            'message' => 'Employee account is inactive or session has been revoked.',
        ]);

    $this->assertGuest();

    $this->actingAs($user)
        ->getJson('/app')
        ->assertForbidden()
        ->assertExactJson([
            'message' => 'Employee account is inactive or session has been revoked.',
        ]);

    $this->assertGuest();
});

it('keeps pre-revocation sessions invalid even after reactivation while allowing a fresh SSO login', function (): void {
    $user = User::factory()->create([
        'email' => 'reactivated@acme.com',
        'status' => UserStatusEnum::Active,
    ]);

    Carbon::setTestNow(Carbon::parse('2026-11-25 10:00:00.000000'));
    mockGoogleSsoLogin('reactivated@acme.com', 'google-sub-reactivated');
    $this->get('/auth/google/callback?code=c1&state=s1')->assertRedirect('/app');
    $this->get('/app')->assertOk();

    Carbon::setTestNow(Carbon::parse('2026-11-25 10:01:00.000000'));
    $user->update(['status' => UserStatusEnum::Suspended]);

    // Reactivate the employee before the old session hits /app again
    Carbon::setTestNow(Carbon::parse('2026-11-25 10:02:00.000000'));
    $user->update(['status' => UserStatusEnum::Active]);

    // The pre-revocation session must STILL be rejected because auth.authenticated_at <= auth:session_revoked_at
    $this->get('/app')
        ->assertRedirect('/login')
        ->assertSessionHasErrors('sso');
    $this->assertGuest();

    // A fresh SSO login after the revocation timestamp succeeds
    Carbon::setTestNow(Carbon::parse('2026-11-25 10:03:00.000000'));
    mockGoogleSsoLogin('reactivated@acme.com', 'google-sub-reactivated');
    $this->get('/auth/google/callback?code=c2&state=s2')->assertRedirect('/app');
    $this->assertAuthenticatedAs($user);
    $this->get('/app')->assertOk();
});

it('revokes Redis session timestamp when SsoController callback catches EmployeeAccountInactiveException', function (): void {
    $user = User::factory()->suspended()->create([
        'email' => 'inactive.sso@acme.com',
    ]);

    expect(Cache::get(RevokeEmployeeSessionsAction::cacheKey((string) $user->id)))
        ->not->toBeNull();
    Cache::flush();

    mockGoogleSsoLogin('inactive.sso@acme.com', 'google-sub-inactive-rev');
    $this->get('/auth/google/callback?code=c1&state=s1')->assertForbidden();

    expect((int) Cache::get(RevokeEmployeeSessionsAction::cacheKey((string) $user->id)))
        ->toBeGreaterThan(0);

    // Even if an existing session held a stale User instance in memory, the middleware revokes it
    $this->actingAs($user)
        ->get('/app')
        ->assertRedirect('/login')
        ->assertSessionHasErrors('sso');
});

it('fails closed in VisibleScopeResolver for unsaved, trashed, and inactive users including admins and produces valid SQL', function (): void {
    Schema::dropIfExists('silo_revocation_test_records');
    Schema::create('silo_revocation_test_records', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->uuid('owner_id');
        $table->uuid('account_manager_id')->nullable();
        $table->string('title');
        $table->timestampsTz();
    });

    $recordModel = new class extends Model
    {
        use HasSiloVisibility;
        use HasUuidV7;

        protected $table = 'silo_revocation_test_records';

        protected $guarded = [];

        public function accountManagerColumn(): ?string
        {
            return 'account_manager_id';
        }
    };

    Relation::morphMap([
        'silo_revocation_test_record' => $recordModel::class,
    ]);

    $resolver = app(VisibleScopeResolver::class);

    $unsavedUser = new User;
    $unsavedScope = $resolver->for($unsavedUser);
    expect($unsavedScope->isUnrestrictedAdmin)->toBeFalse()
        ->and($unsavedScope->siloUserIds)->toBe([]);

    $suspendedAdmin = User::factory()->create(['status' => UserStatusEnum::Active]);
    $suspendedAdmin->assignRole(RoleEnum::Admin->value);
    $recordModel->newQuery()->create([
        'owner_id' => $suspendedAdmin->id,
        'title' => 'Admin Record',
    ]);

    $suspendedAdmin->update(['status' => UserStatusEnum::Suspended]);
    $suspendedScope = $resolver->for($suspendedAdmin);
    expect($suspendedScope->isUnrestrictedAdmin)->toBeFalse()
        ->and($suspendedScope->siloUserIds)->toBe([])
        ->and($recordModel->newQuery()->visibleTo($suspendedAdmin)->pluck('id')->all())->toBe([]);

    $trashedUser = User::factory()->create(['status' => UserStatusEnum::Active]);
    $recordModel->newQuery()->create([
        'owner_id' => $trashedUser->id,
        'title' => 'Trashed User Record',
    ]);
    $trashedUser->delete();

    $trashedScope = $resolver->for($trashedUser);
    expect($trashedScope->isUnrestrictedAdmin)->toBeFalse()
        ->and($trashedScope->siloUserIds)->toBe([])
        ->and($recordModel->newQuery()->visibleTo($trashedUser)->pluck('id')->all())->toBe([]);
});

it('atomically upserts entity collaborators via GrantEntityCollaboratorAction without duplicate key violations', function (): void {
    Schema::dropIfExists('collab_upsert_test_records');
    Schema::create('collab_upsert_test_records', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->uuid('owner_id');
        $table->timestampsTz();
    });

    $recordModel = new class extends Model
    {
        use HasUuidV7;

        protected $table = 'collab_upsert_test_records';

        protected $guarded = [];
    };

    Relation::morphMap([
        'collab_upsert_test_record' => $recordModel::class,
    ]);

    $owner = User::factory()->create();
    $collaboratorUser = User::factory()->create();
    $grantor = User::factory()->create();
    $record = $recordModel->newQuery()->create(['owner_id' => $owner->id]);

    $action = app(GrantEntityCollaboratorAction::class);

    $first = $action->execute(
        collaboratable: $record,
        user: $collaboratorUser,
        accessLevel: CollaboratorAccessLevel::Viewer,
        roleLabel: CollaboratorRoleLabel::Observer,
        grantedBy: $owner,
    );

    $second = $action->execute(
        collaboratable: $record,
        user: $collaboratorUser,
        accessLevel: CollaboratorAccessLevel::Manager,
        roleLabel: CollaboratorRoleLabel::ProjectManager,
        grantedBy: $grantor,
    );

    expect($second->id)->toBe($first->id)
        ->and($second->access_level)->toBe(CollaboratorAccessLevel::Manager)
        ->and($second->role_label)->toBe(CollaboratorRoleLabel::ProjectManager)
        ->and($second->granted_by_id)->toBe((string) $grantor->id);
});

it('creates UUIDv7 employees via User::factory() and seeds roles, department, and active admin via DatabaseSeeder', function (): void {
    expect(file_exists(app_path('Models/User.php')))->toBeFalse()
        ->and(class_exists('App\\Models\\User'))->toBeFalse();

    $user = User::factory()->create();
    expect(Str::isUuid($user->id))->toBeTrue()
        ->and($user->status)->toBe(UserStatusEnum::Active)
        ->and($user->department)->not->toBeNull()
        ->and($user->employee_code)->toStartWith('EMP-');

    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    $seededAdmin = User::query()->where('email', 'admin@example.com')->first();
    expect($seededAdmin)->not->toBeNull()
        ->and($seededAdmin->status)->toBe(UserStatusEnum::Active)
        ->and($seededAdmin->hasRole(RoleEnum::Admin->value))->toBeTrue();
});
