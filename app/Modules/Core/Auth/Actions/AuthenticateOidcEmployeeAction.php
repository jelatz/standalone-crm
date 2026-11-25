<?php

namespace App\Modules\Core\Auth\Actions;

use App\Modules\Core\Auth\Data\OidcProfileData;
use App\Modules\Core\Auth\Enums\OidcProviderEnum;
use App\Modules\Core\Auth\Exceptions\EmployeeAccountInactiveException;
use App\Modules\Core\Auth\Exceptions\EmployeeNotProvisionedException;
use App\Modules\Core\Auth\Exceptions\OidcTenantDeniedException;
use App\Modules\Core\Auth\Models\OauthIdentity;
use App\Modules\Core\Users\Actions\RebuildUserHierarchyClosureAction;
use App\Modules\Core\Users\Enums\UserStatusEnum;
use App\Modules\Core\Users\Models\Department;
use App\Modules\Core\Users\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SocialiteProviders\Microsoft\Provider as MicrosoftProvider;

class AuthenticateOidcEmployeeAction
{
    public function __construct(
        protected RebuildUserHierarchyClosureAction $rebuildHierarchy,
    ) {}

    public function execute(OidcProfileData $profile): User
    {
        $email = mb_strtolower(trim($profile->email));
        $tenantId = mb_strtolower(trim($profile->providerTenantId));
        $providerUserId = trim($profile->providerUserId);

        if (! $profile->emailVerified || $email === '' || $providerUserId === '' || $tenantId === '') {
            throw new OidcTenantDeniedException('OIDC identity claims could not be verified.');
        }

        if (! $this->isTenantAllowed($profile->provider, $tenantId, $email)) {
            throw new OidcTenantDeniedException('OIDC tenant or domain is not authorized for this workspace.');
        }

        return DB::transaction(function () use ($profile, $email, $tenantId, $providerUserId): User {
            $user = User::withTrashed()->where('email', $email)->first();

            if ($user === null) {
                if (! config('sso.jit_provision_enabled', false)) {
                    throw new EmployeeNotProvisionedException('Employee account is not provisioned.');
                }

                $user = $this->provisionEmployee($email, $profile->name);
            } elseif ($user->trashed() || $user->status !== UserStatusEnum::Active) {
                throw new EmployeeAccountInactiveException('Employee account is inactive.');
            }

            $existing = OauthIdentity::query()
                ->where('provider', $profile->provider->value)
                ->where('provider_user_id', $providerUserId)
                ->lockForUpdate()
                ->first();

            if ($existing !== null && (string) $existing->user_id !== (string) $user->id) {
                throw new OidcTenantDeniedException('OIDC identity is already linked to another employee.');
            }

            $scopes = array_values(array_filter(
                $profile->scopes,
                static fn (mixed $scope): bool => is_string($scope) && $scope !== '',
            ));

            $tokenExpiresAt = $profile->expiresIn !== null
                ? now()->addSeconds($profile->expiresIn)
                : null;

            if ($existing !== null) {
                $existing->forceFill([
                    'provider_tenant_id' => $tenantId,
                    'email' => $email,
                    'access_token' => $profile->accessToken,
                    'refresh_token' => $profile->refreshToken ?? $existing->refresh_token,
                    'token_expires_at' => $tokenExpiresAt,
                    'scopes' => $scopes,
                ])->save();
            } else {
                OauthIdentity::query()->create([
                    'user_id' => $user->id,
                    'provider' => $profile->provider,
                    'provider_tenant_id' => $tenantId,
                    'provider_user_id' => $providerUserId,
                    'email' => $email,
                    'access_token' => $profile->accessToken,
                    'refresh_token' => $profile->refreshToken,
                    'token_expires_at' => $tokenExpiresAt,
                    'scopes' => $scopes,
                ]);
            }

            $user->forceFill(['last_seen_at' => now()])->save();

            return $user;
        });
    }

    protected function isTenantAllowed(OidcProviderEnum $provider, string $tenantId, string $email): bool
    {
        if ($provider === OidcProviderEnum::Google) {
            if (str_ends_with($email, '@gmail.com') || str_ends_with($email, '@googlemail.com')) {
                return false;
            }

            $allowedDomains = array_map(
                static fn (mixed $domain): string => mb_strtolower(trim((string) $domain)),
                (array) config('sso.allowed_google_domains', []),
            );

            return in_array($tenantId, $allowedDomains, true);
        }

        if ($tenantId === MicrosoftProvider::MS_ENTRA_CONSUMER_TENANT_ID) {
            return false;
        }

        $allowedTenants = array_map(
            static fn (mixed $allowed): string => mb_strtolower(trim((string) $allowed)),
            (array) config('sso.allowed_microsoft_tenant_ids', []),
        );

        return in_array($tenantId, $allowedTenants, true);
    }

    protected function provisionEmployee(string $email, string $name): User
    {
        $departmentSlug = (string) config('sso.default_department_slug', 'general');
        $department = Department::query()->firstOrCreate(
            ['slug' => $departmentSlug],
            ['name' => Str::headline($departmentSlug)],
        );

        $displayName = trim($name) !== '' ? trim($name) : Str::before($email, '@');
        $employeeCode = 'EMP-'.strtoupper(substr(str_replace('-', '', (string) Str::uuid7()), -10));

        $user = User::query()->create([
            'employee_code' => $employeeCode,
            'name' => Str::limit($displayName, 150, ''),
            'email' => $email,
            'department_id' => $department->id,
            'status' => UserStatusEnum::Active,
        ]);

        $this->rebuildHierarchy->execute($user);

        return $user;
    }
}
