<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Modules\Core\Auth\Actions\AuthenticateOidcEmployeeAction;
use App\Modules\Core\Auth\Data\OidcProfileData;
use App\Modules\Core\Auth\Enums\OidcProviderEnum;
use App\Modules\Core\Auth\Exceptions\EmployeeAccountInactiveException;
use App\Modules\Core\Auth\Exceptions\EmployeeNotProvisionedException;
use App\Modules\Core\Auth\Exceptions\OidcTenantDeniedException;
use App\Modules\Core\Auth\Models\OauthIdentity;
use App\Modules\Core\Rbac\Enums\RoleEnum;
use App\Modules\Core\Users\Enums\UserStatusEnum;
use App\Modules\Core\Users\Models\Department;
use App\Modules\Core\Users\Models\User;
use Database\Seeders\RolesPermissionsSeeder;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Contracts\Factory as SocialiteFactory;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteTwoUser;
use SocialiteProviders\Google\Provider as GoogleProvider;
use SocialiteProviders\Microsoft\Provider as MicrosoftProvider;
use Spatie\Permission\PermissionRegistrar;

function createSsoDepartment(string $slug = 'general', string $name = 'General'): Department
{
    return Department::query()->firstOrCreate(
        ['slug' => $slug],
        ['name' => $name],
    );
}

function createSsoEmployee(
    Department $department,
    string $email,
    UserStatusEnum $status = UserStatusEnum::Active,
    string $code = 'EMP-1001',
    string $name = 'Alice Smith',
    ?string $jobTitle = 'Account Executive',
): User {
    return User::query()->create([
        'employee_code' => $code,
        'name' => $name,
        'email' => $email,
        'department_id' => $department->id,
        'job_title' => $jobTitle,
        'status' => $status,
    ]);
}

function makeFakeSocialiteUser(
    string $id,
    string $email,
    string $name,
    array $rawClaims,
    string $token = 'oauth-access-token',
    ?string $refreshToken = 'oauth-refresh-token',
    ?int $expiresIn = 3600,
    array $approvedScopes = ['openid', 'profile', 'email'],
): SocialiteTwoUser {
    $socialiteUser = (new SocialiteTwoUser)->setRaw($rawClaims)->map([
        'id' => $id,
        'name' => $name,
        'email' => $email,
    ]);

    $socialiteUser
        ->setToken($token)
        ->setRefreshToken($refreshToken)
        ->setExpiresIn($expiresIn)
        ->setApprovedScopes($approvedScopes);

    return $socialiteUser;
}

function mockSocialiteCallback(string $provider, SocialiteTwoUser $socialiteUser): void
{
    $mockProvider = Mockery::mock();
    $mockProvider->shouldReceive('user')->once()->andReturn($socialiteUser);

    $factory = Mockery::mock(SocialiteFactory::class);
    $factory->shouldReceive('driver')->with($provider)->once()->andReturn($mockProvider);

    app()->instance(SocialiteFactory::class, $factory);
    Socialite::swap($factory);
}

function mockMicrosoftProviderCallback(SocialiteTwoUser $socialiteUser, ?stdClass $claims): void
{
    $mockProvider = Mockery::mock(MicrosoftProvider::class);
    $mockProvider->shouldReceive('user')->once()->andReturn($socialiteUser);
    $mockProvider->shouldReceive('getClaims')->once()->andReturn($claims);

    $factory = Mockery::mock(SocialiteFactory::class);
    $factory->shouldReceive('driver')->with('microsoft')->once()->andReturn($mockProvider);

    app()->instance(SocialiteFactory::class, $factory);
    Socialite::swap($factory);
}

beforeEach(function (): void {
    Cache::flush();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    Config::set('sso.allowed_google_domains', ['acme.com']);
    Config::set('sso.allowed_microsoft_tenant_ids', ['11111111-2222-3333-4444-555555555555']);
    Config::set('sso.jit_provision_enabled', false);
    Config::set('sso.default_department_slug', 'general');
    Config::set('services.google', [
        'client_id' => 'test-google-client-id',
        'client_secret' => 'test-google-client-secret',
        'redirect' => 'http://localhost/auth/google/callback',
    ]);
    Config::set('services.microsoft', [
        'client_id' => 'test-ms-client-id',
        'client_secret' => 'test-ms-client-secret',
        'redirect' => 'http://localhost/auth/microsoft/callback',
        'tenant' => 'organizations',
    ]);

    $this->seed(RolesPermissionsSeeder::class);
});

afterEach(function (): void {
    Socialite::clearResolvedInstances();
    Mockery::close();
    Cache::flush();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('registers Google and Microsoft SocialiteProviders drivers via SocialiteWasCalled', function (): void {
    expect(Socialite::driver('google'))->toBeInstanceOf(GoogleProvider::class)
        ->and(Socialite::driver('microsoft'))->toBeInstanceOf(MicrosoftProvider::class);
});

it('redirects to the Socialite provider for valid OIDC providers', function (): void {
    $mockGoogle = Mockery::mock();
    $mockGoogle->shouldReceive('redirect')
        ->once()
        ->andReturn(new RedirectResponse('https://accounts.google.com/o/oauth2/auth?state=xyz'));

    $mockMicrosoft = Mockery::mock();
    $mockMicrosoft->shouldReceive('redirect')
        ->once()
        ->andReturn(new RedirectResponse('https://login.microsoftonline.com/organizations/oauth2/v2.0/authorize?state=abc'));

    $factory = Mockery::mock(SocialiteFactory::class);
    $factory->shouldReceive('driver')->with('google')->once()->andReturn($mockGoogle);
    $factory->shouldReceive('driver')->with('microsoft')->once()->andReturn($mockMicrosoft);
    Socialite::swap($factory);

    $this->get('/auth/google/redirect')
        ->assertRedirect('https://accounts.google.com/o/oauth2/auth?state=xyz');

    $this->get('/auth/microsoft/redirect')
        ->assertRedirect('https://login.microsoftonline.com/organizations/oauth2/v2.0/authorize?state=abc');
});

it('authenticates valid Google Workspace hd login with citext email match, creates oauth_identities row, and redirects to /app', function (): void {
    $department = createSsoDepartment();
    $user = createSsoEmployee($department, 'Alice.Smith@acme.com');

    $socialiteUser = makeFakeSocialiteUser(
        id: 'google-sub-001',
        email: 'alice.smith@acme.com',
        name: 'Alice Smith',
        rawClaims: [
            'sub' => 'google-sub-001',
            'email' => 'alice.smith@acme.com',
            'email_verified' => true,
            'hd' => 'ACME.COM',
        ],
        token: 'google-access-token',
        refreshToken: 'google-refresh-token',
    );

    mockSocialiteCallback('google', $socialiteUser);

    $response = $this->get('/auth/google/callback?code=oauth-code&state=oauth-state');

    $response->assertRedirect('/app');
    $this->assertAuthenticatedAs($user);

    $identity = OauthIdentity::query()->where('user_id', $user->id)->first();
    expect($identity)->not->toBeNull()
        ->and($identity->provider)->toBe(OidcProviderEnum::Google)
        ->and($identity->provider_user_id)->toBe('google-sub-001')
        ->and(strtolower($identity->provider_tenant_id))->toBe('acme.com')
        ->and($identity->scopes)->toBe(['openid', 'profile', 'email']);

    $user->refresh();
    expect($user->last_seen_at)->not->toBeNull();
});

it('rejects consumer gmail accounts, mismatched Google hd claims, and unverified Google emails with 403 without creating oauth_identities', function (
    string $email,
    array $rawClaims,
): void {
    $department = createSsoDepartment();
    createSsoEmployee($department, 'employee@acme.com');

    $socialiteUser = makeFakeSocialiteUser(
        id: 'google-sub-bad',
        email: $email,
        name: 'Denied User',
        rawClaims: $rawClaims,
    );

    mockSocialiteCallback('google', $socialiteUser);

    $this->get('/auth/google/callback?code=oauth-code&state=oauth-state')->assertForbidden();
    $this->assertGuest();
    expect(OauthIdentity::query()->count())->toBe(0);
})->with([
    'consumer gmail without hd' => [
        'someone@gmail.com',
        ['sub' => 'g-1', 'email' => 'someone@gmail.com', 'email_verified' => true],
    ],
    'mismatched workspace hd' => [
        'employee@acme.com',
        ['sub' => 'g-2', 'email' => 'employee@acme.com', 'email_verified' => true, 'hd' => 'competitor.com'],
    ],
    'unverified email false' => [
        'employee@acme.com',
        ['sub' => 'g-3', 'email' => 'employee@acme.com', 'email_verified' => false, 'hd' => 'acme.com'],
    ],
    'missing email_verified claim' => [
        'employee@acme.com',
        ['sub' => 'g-4', 'email' => 'employee@acme.com', 'hd' => 'acme.com'],
    ],
]);

it('authenticates valid Microsoft 365 tid login and creates oauth_identities row', function (): void {
    $department = createSsoDepartment();
    $user = createSsoEmployee($department, 'bob@acme.com', UserStatusEnum::Active, 'EMP-1002', 'Bob Jones');

    $socialiteUser = makeFakeSocialiteUser(
        id: 'ms-oid-001',
        email: 'bob@acme.com',
        name: 'Bob Jones',
        rawClaims: [
            'id' => 'ms-oid-001',
            'userPrincipalName' => 'bob@acme.com',
            'tid' => '11111111-2222-3333-4444-555555555555',
        ],
        token: 'ms-access-token',
        refreshToken: 'ms-refresh-token',
        approvedScopes: ['openid', 'profile', 'User.Read'],
    );

    mockSocialiteCallback('microsoft', $socialiteUser);

    $this->get('/auth/microsoft/callback?code=oauth-code&state=oauth-state')
        ->assertRedirect('/app');

    $this->assertAuthenticatedAs($user);

    $identity = OauthIdentity::query()->where('user_id', $user->id)->first();
    expect($identity)->not->toBeNull()
        ->and($identity->provider)->toBe(OidcProviderEnum::Microsoft)
        ->and($identity->provider_user_id)->toBe('ms-oid-001')
        ->and($identity->provider_tenant_id)->toBe('11111111-2222-3333-4444-555555555555');
});

it('rejects wrong Microsoft tenant tid and Microsoft consumer tenant with 403', function (string $tenantId): void {
    $department = createSsoDepartment();
    createSsoEmployee($department, 'bob@acme.com', UserStatusEnum::Active, 'EMP-1003', 'Bob Jones');

    $socialiteUser = makeFakeSocialiteUser(
        id: 'ms-oid-bad',
        email: 'bob@acme.com',
        name: 'Bob Jones',
        rawClaims: [
            'id' => 'ms-oid-bad',
            'userPrincipalName' => 'bob@acme.com',
            'tid' => $tenantId,
        ],
    );

    mockSocialiteCallback('microsoft', $socialiteUser);

    $this->get('/auth/microsoft/callback?code=oauth-code&state=oauth-state')->assertForbidden();
    $this->assertGuest();
    expect(OauthIdentity::query()->count())->toBe(0);
})->with([
    'foreign entra tenant' => ['99999999-8888-7777-6666-555555555555'],
    'microsoft consumer msa tenant' => ['9188040d-6c67-4c5b-b112-36a304b66dad'],
]);

it('blocks suspended and offboarded users with 403 even with valid tenant OIDC claims', function (UserStatusEnum $status): void {
    $department = createSsoDepartment();
    createSsoEmployee($department, 'inactive@acme.com', $status, 'EMP-1004', 'Inactive User');

    $socialiteUser = makeFakeSocialiteUser(
        id: 'google-sub-inactive',
        email: 'inactive@acme.com',
        name: 'Inactive User',
        rawClaims: [
            'sub' => 'google-sub-inactive',
            'email' => 'inactive@acme.com',
            'email_verified' => true,
            'hd' => 'acme.com',
        ],
    );

    mockSocialiteCallback('google', $socialiteUser);

    $this->get('/auth/google/callback?code=oauth-code&state=oauth-state')->assertForbidden();
    $this->assertGuest();
    expect(OauthIdentity::query()->count())->toBe(0);
})->with([
    'suspended user' => [UserStatusEnum::Suspended],
    'offboarded user' => [UserStatusEnum::Offboarded],
]);

it('blocks unprovisioned employees when JIT provisioning is disabled and provisions when enabled', function (): void {
    createSsoDepartment('general', 'General');

    $profile = new OidcProfileData(
        provider: OidcProviderEnum::Google,
        providerUserId: 'google-sub-jit',
        providerTenantId: 'acme.com',
        email: 'new.hire@acme.com',
        emailVerified: true,
        name: 'New Hire',
        accessToken: 'jit-access-token',
        refreshToken: 'jit-refresh-token',
        expiresIn: 3600,
        scopes: ['openid', 'profile', 'email'],
    );

    $action = app(AuthenticateOidcEmployeeAction::class);

    expect(fn (): User => $action->execute($profile))
        ->toThrow(EmployeeNotProvisionedException::class);
    expect(User::query()->where('email', 'new.hire@acme.com')->exists())->toBeFalse();

    Config::set('sso.jit_provision_enabled', true);

    $provisioned = $action->execute($profile);
    expect($provisioned->status)->toBe(UserStatusEnum::Active)
        ->and($provisioned->department->slug)->toBe('general')
        ->and($provisioned->oauthIdentities()->count())->toBe(1);
});

it('throws domain exceptions cleanly when action rejects invalid tenant or inactive account', function (): void {
    $department = createSsoDepartment();
    createSsoEmployee($department, 'suspended@acme.com', UserStatusEnum::Suspended, 'EMP-1005', 'Suspended User');

    $action = app(AuthenticateOidcEmployeeAction::class);

    $badTenantProfile = new OidcProfileData(
        provider: OidcProviderEnum::Microsoft,
        providerUserId: 'ms-sub-bad',
        providerTenantId: '00000000-0000-0000-0000-000000000000',
        email: 'suspended@acme.com',
        emailVerified: true,
        name: 'Suspended User',
        accessToken: 'token',
    );

    expect(fn (): User => $action->execute($badTenantProfile))
        ->toThrow(OidcTenantDeniedException::class);

    $inactiveProfile = new OidcProfileData(
        provider: OidcProviderEnum::Google,
        providerUserId: 'google-sub-suspended',
        providerTenantId: 'acme.com',
        email: 'suspended@acme.com',
        emailVerified: true,
        name: 'Suspended User',
        accessToken: 'token',
    );

    expect(fn (): User => $action->execute($inactiveProfile))
        ->toThrow(EmployeeAccountInactiveException::class);
});

it('returns 404 for unknown OIDC providers on redirect and callback routes', function (): void {
    $this->get('/auth/github/redirect')->assertNotFound();
    $this->get('/auth/github/callback')->assertNotFound();
});

it('encrypts oauth access_token and refresh_token at rest and preserves refresh_token when omitted on re-login', function (): void {
    $department = createSsoDepartment();
    $user = createSsoEmployee($department, 'crypto@acme.com', UserStatusEnum::Active, 'EMP-1006', 'Crypto User');

    $firstLogin = makeFakeSocialiteUser(
        id: 'google-sub-crypto',
        email: 'crypto@acme.com',
        name: 'Crypto User',
        rawClaims: [
            'sub' => 'google-sub-crypto',
            'email' => 'crypto@acme.com',
            'email_verified' => true,
            'hd' => 'acme.com',
        ],
        token: 'secret-access-token-xyz',
        refreshToken: 'secret-refresh-token-abc',
    );

    mockSocialiteCallback('google', $firstLogin);
    $this->get('/auth/google/callback?code=oauth-code-1&state=oauth-state')->assertRedirect('/app');

    $identity = OauthIdentity::query()->where('user_id', $user->id)->firstOrFail();
    $rawRow = DB::table('oauth_identities')->where('id', $identity->id)->first();

    expect($rawRow->access_token)->not->toBe('secret-access-token-xyz')
        ->and($rawRow->refresh_token)->not->toBe('secret-refresh-token-abc')
        ->and($identity->access_token)->toBe('secret-access-token-xyz')
        ->and($identity->refresh_token)->toBe('secret-refresh-token-abc');

    $secondLogin = makeFakeSocialiteUser(
        id: 'google-sub-crypto',
        email: 'crypto@acme.com',
        name: 'Crypto User',
        rawClaims: [
            'sub' => 'google-sub-crypto',
            'email' => 'crypto@acme.com',
            'email_verified' => true,
            'hd' => 'acme.com',
        ],
        token: 'rotated-access-token-999',
        refreshToken: null,
    );

    mockSocialiteCallback('google', $secondLogin);
    $this->get('/auth/google/callback?code=oauth-code-2&state=oauth-state')->assertRedirect('/app');

    $identity->refresh();
    expect($identity->access_token)->toBe('rotated-access-token-999')
        ->and($identity->refresh_token)->toBe('secret-refresh-token-abc');
});

it('shares AuthenticatedUserData via Inertia on GET /app, renders login page, and invalidates session on POST /logout', function (): void {
    $this->get('/app')->assertRedirect('/login');

    $this->get('/login')
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('core/pages/LoginPage')
            ->where('auth.user', null)
            ->where('providers.google', true)
            ->where('providers.microsoft', true));

    $department = createSsoDepartment();
    $user = createSsoEmployee($department, 'sales@acme.com', UserStatusEnum::Active, 'EMP-1007', 'Jordan Rep', 'Senior AE');
    $user->assignRole(RoleEnum::Sales->value);

    $this->actingAs($user)
        ->get('/app')
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('auth.user.id', (string) $user->id)
            ->where('auth.user.name', 'Jordan Rep')
            ->where('auth.user.email', 'sales@acme.com')
            ->where('auth.user.employee_code', 'EMP-1007')
            ->where('auth.user.job_title', 'Senior AE')
            ->where('auth.user.department_id', (string) $department->id)
            ->where('auth.user.roles', ['sales'])
            ->where('auth.user.permissions', fn ($permissions): bool => collect($permissions)->contains('deals.view')));

    $this->post('/logout')
        ->assertRedirect('/login');

    $this->assertGuest();
    $this->get('/app')->assertRedirect('/login');
});

it('prevents rebinding an existing provider_user_id to a different employee with 403', function (): void {
    $department = createSsoDepartment();
    $alice = createSsoEmployee($department, 'alice@acme.com', UserStatusEnum::Active, 'EMP-1008', 'Alice');
    createSsoEmployee($department, 'bob@acme.com', UserStatusEnum::Active, 'EMP-1009', 'Bob');

    OauthIdentity::query()->create([
        'user_id' => $alice->id,
        'provider' => OidcProviderEnum::Google,
        'provider_tenant_id' => 'acme.com',
        'provider_user_id' => 'shared-sub-999',
        'email' => 'alice@acme.com',
        'access_token' => 'alice-token',
    ]);

    $forgedCallback = makeFakeSocialiteUser(
        id: 'shared-sub-999',
        email: 'bob@acme.com',
        name: 'Bob',
        rawClaims: [
            'sub' => 'shared-sub-999',
            'email' => 'bob@acme.com',
            'email_verified' => true,
            'hd' => 'acme.com',
        ],
    );

    mockSocialiteCallback('google', $forgedCallback);

    $this->get('/auth/google/callback?code=oauth-code&state=oauth-state')->assertForbidden();
    $this->assertGuest();
});

it('redirects cleanly to /login with error on OAuth cancellation, missing code, invalid state, or token exchange ClientException', function (): void {
    $this->get('/auth/google/callback?error=access_denied&state=valid-state')
        ->assertRedirect('/login')
        ->assertSessionHasErrors('sso');
    $this->assertGuest();

    $this->get('/auth/microsoft/callback?state=valid-state')
        ->assertRedirect('/login')
        ->assertSessionHasErrors('sso');
    $this->assertGuest();

    $stateFailProvider = Mockery::mock();
    $stateFailProvider->shouldReceive('user')->once()->andThrow(new InvalidStateException);

    $clientFailProvider = Mockery::mock();
    $clientFailProvider->shouldReceive('user')->once()->andThrow(new ClientException(
        '400 Bad Request',
        new Psr7Request('POST', 'https://oauth2.googleapis.com/token'),
        new Psr7Response(400, [], '{"error":"invalid_grant"}'),
    ));

    $factory = Mockery::mock(SocialiteFactory::class);
    $factory->shouldReceive('driver')->with('google')->once()->andReturn($stateFailProvider);
    $factory->shouldReceive('driver')->with('microsoft')->once()->andReturn($clientFailProvider);
    Socialite::swap($factory);

    $this->get('/auth/google/callback?code=stale-code&state=bad-state')
        ->assertRedirect('/login')
        ->assertSessionHasErrors('sso');

    $this->get('/auth/microsoft/callback?code=expired-code&state=valid-state')
        ->assertRedirect('/login')
        ->assertSessionHasErrors('sso');

    $this->assertGuest();
    expect(OauthIdentity::query()->count())->toBe(0);
});

it('extracts Microsoft tid, xms_edov, and email from MicrosoftProvider getClaims() when omitted from Graph raw response', function (): void {
    $department = createSsoDepartment();
    $user = createSsoEmployee($department, 'claims.user@acme.com', UserStatusEnum::Active, 'EMP-1010', 'Claims User');

    $graphUser = (new SocialiteTwoUser)->setRaw([
        'id' => 'ms-oid-claims-001',
        'displayName' => 'Claims User',
    ])->map([
        'id' => 'ms-oid-claims-001',
        'name' => 'Claims User',
        'email' => null,
    ])->setToken('ms-claims-token')
        ->setRefreshToken('ms-claims-refresh')
        ->setExpiresIn(3600)
        ->setApprovedScopes(['openid', 'profile', 'User.Read']);

    $idTokenClaims = (object) [
        'tid' => '11111111-2222-3333-4444-555555555555',
        'xms_edov' => '1',
        'email' => 'claims.user@acme.com',
    ];

    mockMicrosoftProviderCallback($graphUser, $idTokenClaims);

    $this->get('/auth/microsoft/callback?code=ms-code&state=ms-state')
        ->assertRedirect('/app');

    $this->assertAuthenticatedAs($user);

    $identity = OauthIdentity::query()->where('user_id', $user->id)->first();
    expect($identity)->not->toBeNull()
        ->and($identity->provider)->toBe(OidcProviderEnum::Microsoft)
        ->and($identity->provider_tenant_id)->toBe('11111111-2222-3333-4444-555555555555')
        ->and($identity->email)->toBe('claims.user@acme.com');
});

it('rejects Microsoft login with 403 when xms_edov claim is falsy in JWT claims', function (mixed $xmsEdov): void {
    $department = createSsoDepartment();
    createSsoEmployee($department, 'unverified.ms@acme.com', UserStatusEnum::Active, 'EMP-1011', 'Unverified MS');

    $graphUser = makeFakeSocialiteUser(
        id: 'ms-oid-unverified',
        email: 'unverified.ms@acme.com',
        name: 'Unverified MS',
        rawClaims: [
            'id' => 'ms-oid-unverified',
            'userPrincipalName' => 'unverified.ms@acme.com',
        ],
    );

    mockMicrosoftProviderCallback($graphUser, (object) [
        'tid' => '11111111-2222-3333-4444-555555555555',
        'xms_edov' => $xmsEdov,
    ]);

    $this->get('/auth/microsoft/callback?code=ms-code&state=ms-state')
        ->assertForbidden();

    $this->assertGuest();
    expect(OauthIdentity::query()->count())->toBe(0);
})->with([
    'boolean false' => [false],
    'string zero' => ['0'],
]);

it('verifies PostgreSQL citext column, unique index, and web middleware on module SSO routes', function (): void {
    $column = DB::selectOne(
        "SELECT udt_name FROM information_schema.columns WHERE table_name = 'oauth_identities' AND column_name = 'email'"
    );
    expect($column?->udt_name)->toBe('citext');

    $indexes = collect(DB::select("SELECT indexname FROM pg_indexes WHERE tablename = 'oauth_identities'"))
        ->pluck('indexname')
        ->all();
    expect($indexes)->toContain('uq_oauth_identities_provider_user', 'idx_oauth_identities_user_provider');

    $loginRoute = Route::getRoutes()->getByName('login');
    $callbackRoute = Route::getRoutes()->getByName('auth.sso.callback');
    $webGroup = app(HttpKernel::class)->getMiddlewareGroups()['web'] ?? [];

    expect($loginRoute)->not->toBeNull()
        ->and($loginRoute->gatherMiddleware())->toContain('web')
        ->and($callbackRoute)->not->toBeNull()
        ->and($callbackRoute->gatherMiddleware())->toContain('web')
        ->and($webGroup)->toContain(HandleInertiaRequests::class);
});
