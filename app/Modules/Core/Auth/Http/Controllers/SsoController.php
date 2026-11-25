<?php

namespace App\Modules\Core\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Auth\Actions\AuthenticateOidcEmployeeAction;
use App\Modules\Core\Auth\Data\OidcProfileData;
use App\Modules\Core\Auth\Enums\OidcProviderEnum;
use App\Modules\Core\Auth\Exceptions\EmployeeAccountInactiveException;
use App\Modules\Core\Auth\Exceptions\EmployeeNotProvisionedException;
use App\Modules\Core\Auth\Exceptions\OidcTenantDeniedException;
use Illuminate\Http\RedirectResponse as IlluminateRedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Laravel\Socialite\Contracts\Provider as SocialiteProvider;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Mockery\MockInterface;
use SocialiteProviders\Microsoft\Provider as MicrosoftProvider;
use Symfony\Component\HttpFoundation\RedirectResponse;

class SsoController extends Controller
{
    public function showLogin(): InertiaResponse
    {
        return Inertia::render('core/pages/LoginPage', [
            'providers' => [
                'google' => true,
                'microsoft' => true,
            ],
        ]);
    }

    public function redirect(string $provider): RedirectResponse
    {
        $oidcProvider = OidcProviderEnum::tryFrom($provider);
        abort_if($oidcProvider === null, 404);

        return Socialite::driver($oidcProvider->value)->redirect();
    }

    public function callback(
        string $provider,
        Request $request,
        AuthenticateOidcEmployeeAction $authenticateOidcEmployee,
    ): IlluminateRedirectResponse {
        $oidcProvider = OidcProviderEnum::tryFrom($provider);
        abort_if($oidcProvider === null, 404);

        try {
            /** @var SocialiteProvider $driver */
            $driver = Socialite::driver($oidcProvider->value);
            $oauthUser = $driver->user();
        } catch (InvalidStateException) {
            return redirect('/login');
        }

        $profile = $this->extractProfile($oidcProvider, $driver, $oauthUser);

        try {
            $user = $authenticateOidcEmployee->execute($profile);
        } catch (OidcTenantDeniedException|EmployeeAccountInactiveException|EmployeeNotProvisionedException $exception) {
            if (Auth::check()) {
                Auth::logout();

                if ($request->hasSession()) {
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();
                }
            }

            throw $exception;
        }

        Auth::login($user);

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return redirect()->intended('/app');
    }

    public function logout(Request $request): IlluminateRedirectResponse
    {
        Auth::logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return redirect('/login');
    }

    protected function extractProfile(
        OidcProviderEnum $provider,
        object $driver,
        SocialiteUser $oauthUser,
    ): OidcProfileData {
        $raw = method_exists($oauthUser, 'getRaw') && is_array($oauthUser->getRaw())
            ? $oauthUser->getRaw()
            : (is_array($oauthUser->user ?? null) ? $oauthUser->user : []);

        $providerUserId = (string) ($oauthUser->getId() ?? Arr::get($raw, 'sub') ?? Arr::get($raw, 'id', ''));
        $email = trim((string) (
            ($oauthUser->getEmail() ?: null)
            ?? (Arr::get($raw, 'email') ?: null)
            ?? (Arr::get($raw, 'mail') ?: null)
            ?? (Arr::get($raw, 'userPrincipalName') ?: null)
            ?? ''
        ));
        $name = trim((string) (
            ($oauthUser->getName() ?: null)
            ?? (Arr::get($raw, 'name') ?: null)
            ?? (Arr::get($raw, 'displayName') ?: null)
            ?? $email
        ));

        [$providerTenantId, $emailVerified] = $this->extractTenantAndVerification($provider, $driver, $raw);

        $accessToken = (string) ($oauthUser->token ?? '');
        $refreshToken = is_string($oauthUser->refreshToken ?? null) && $oauthUser->refreshToken !== ''
            ? $oauthUser->refreshToken
            : null;
        $expiresIn = isset($oauthUser->expiresIn) && is_numeric($oauthUser->expiresIn)
            ? (int) $oauthUser->expiresIn
            : null;
        $scopes = is_array($oauthUser->approvedScopes ?? null)
            ? array_values(array_filter(
                $oauthUser->approvedScopes,
                static fn (mixed $scope): bool => is_string($scope) && $scope !== '',
            ))
            : [];

        return new OidcProfileData(
            provider: $provider,
            providerUserId: $providerUserId,
            providerTenantId: $providerTenantId,
            email: $email,
            emailVerified: $emailVerified,
            name: $name,
            accessToken: $accessToken,
            refreshToken: $refreshToken,
            expiresIn: $expiresIn,
            scopes: $scopes,
        );
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{0: string, 1: bool}
     */
    protected function extractTenantAndVerification(
        OidcProviderEnum $provider,
        object $driver,
        array $raw,
    ): array {
        if ($provider === OidcProviderEnum::Google) {
            return [
                trim((string) Arr::get($raw, 'hd', '')),
                Arr::get($raw, 'email_verified') === true,
            ];
        }

        $claims = null;
        if ($driver instanceof MicrosoftProvider && ! ($driver instanceof MockInterface)) {
            $claims = $driver->getClaims();
        }

        $tenantId = Arr::get($raw, 'tid')
            ?? Arr::get($raw, 'tenant.id')
            ?? ($claims?->tid ?? null);
        $normalizedTenantId = trim((string) ($tenantId ?? ''));

        if (array_key_exists('email_verified', $raw)) {
            $emailVerified = Arr::get($raw, 'email_verified') === true;
        } elseif (array_key_exists('xms_edov', $raw)) {
            $emailVerified = (bool) Arr::get($raw, 'xms_edov') === true;
        } elseif ($claims !== null && property_exists($claims, 'email_verified')) {
            $emailVerified = $claims->email_verified === true;
        } elseif ($claims !== null && property_exists($claims, 'xms_edov')) {
            $emailVerified = (bool) $claims->xms_edov === true;
        } else {
            $emailVerified = $normalizedTenantId !== ''
                && mb_strtolower($normalizedTenantId) !== MicrosoftProvider::MS_ENTRA_CONSUMER_TENANT_ID;
        }

        return [$normalizedTenantId, $emailVerified];
    }
}
