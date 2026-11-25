<?php

namespace App\Modules\Core\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Auth\Actions\AuthenticateOidcEmployeeAction;
use App\Modules\Core\Auth\Actions\RevokeEmployeeSessionsAction;
use App\Modules\Core\Auth\Data\OidcProfileData;
use App\Modules\Core\Auth\Enums\OidcProviderEnum;
use App\Modules\Core\Auth\Exceptions\EmployeeAccountInactiveException;
use App\Modules\Core\Auth\Exceptions\EmployeeNotProvisionedException;
use App\Modules\Core\Auth\Exceptions\OidcTenantDeniedException;
use App\Modules\Core\Users\Models\User;
use GuzzleHttp\Exception\GuzzleException;
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
use SocialiteProviders\Microsoft\Provider as MicrosoftProvider;
use stdClass;
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
        RevokeEmployeeSessionsAction $revokeEmployeeSessions,
    ): IlluminateRedirectResponse {
        $oidcProvider = OidcProviderEnum::tryFrom($provider);
        abort_if($oidcProvider === null, 404);

        if ($request->filled('error') || ! $request->filled('code')) {
            return redirect('/login')->withErrors([
                'sso' => 'Single sign-on was cancelled or did not return an authorization code.',
            ]);
        }

        try {
            /** @var SocialiteProvider $driver */
            $driver = Socialite::driver($oidcProvider->value);
            $oauthUser = $driver->user();
        } catch (InvalidStateException|GuzzleException) {
            return redirect('/login')->withErrors([
                'sso' => 'Single sign-on could not be completed. Please try again.',
            ]);
        }

        $profile = null;

        try {
            $profile = $this->extractProfile($oidcProvider, $driver, $oauthUser);
            $user = $authenticateOidcEmployee->execute($profile);
        } catch (EmployeeAccountInactiveException $exception) {
            if ($profile !== null && $profile->email !== '') {
                $inactiveUser = User::withTrashed()
                    ->where('email', mb_strtolower(trim($profile->email)))
                    ->first();

                if ($inactiveUser !== null) {
                    $revokeEmployeeSessions->execute($inactiveUser);
                }
            }

            $this->clearAuthenticatedSession($request);

            throw $exception;
        } catch (OidcTenantDeniedException|EmployeeNotProvisionedException $exception) {
            $this->clearAuthenticatedSession($request);

            throw $exception;
        }

        Auth::login($user);

        if ($request->hasSession()) {
            $request->session()->regenerate();
            $request->session()->put('auth.authenticated_at', (int) now()->getPreciseTimestamp(6));
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

    protected function clearAuthenticatedSession(Request $request): void
    {
        if (Auth::check()) {
            Auth::logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
        }
    }

    protected function extractProfile(
        OidcProviderEnum $provider,
        object $driver,
        SocialiteUser $oauthUser,
    ): OidcProfileData {
        $raw = method_exists($oauthUser, 'getRaw') && is_array($oauthUser->getRaw())
            ? $oauthUser->getRaw()
            : (is_array($oauthUser->user ?? null) ? $oauthUser->user : []);

        $claims = $provider === OidcProviderEnum::Microsoft && $driver instanceof MicrosoftProvider
            ? $driver->getClaims()
            : null;

        if ($provider === OidcProviderEnum::Microsoft) {
            $this->assertNoMicrosoftExternalGuest([
                $oauthUser->getEmail(),
                Arr::get($raw, 'email'),
                Arr::get($raw, 'mail'),
                Arr::get($raw, 'userPrincipalName'),
                Arr::get($raw, 'preferred_username'),
                $claims?->email ?? null,
                $claims?->preferred_username ?? null,
                $claims?->upn ?? null,
            ]);
        }

        $providerUserId = $this->firstNonEmptyString(
            $oauthUser->getId(),
            Arr::get($raw, 'sub'),
            Arr::get($raw, 'id'),
        );
        $email = $this->firstNonEmptyString(
            $oauthUser->getEmail(),
            Arr::get($raw, 'email'),
            Arr::get($raw, 'mail'),
            Arr::get($raw, 'userPrincipalName'),
            ($claims?->email ?? null) ?: null,
            ($claims?->preferred_username ?? null) ?: null,
        );
        $name = $this->firstNonEmptyString(
            $oauthUser->getName(),
            Arr::get($raw, 'name'),
            Arr::get($raw, 'displayName'),
            ($claims?->name ?? null) ?: null,
            $email,
        );

        [$providerTenantId, $emailVerified] = $this->extractTenantAndVerification($provider, $claims, $raw);

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
     * @param  array<int, mixed>  $identifiers
     */
    protected function assertNoMicrosoftExternalGuest(array $identifiers): void
    {
        foreach ($identifiers as $identifier) {
            if (is_string($identifier) && str_contains(mb_strtolower($identifier), '#ext#')) {
                throw new OidcTenantDeniedException('Microsoft external guest accounts are not authorized for this workspace.');
            }
        }
    }

    protected function firstNonEmptyString(mixed ...$candidates): string
    {
        foreach ($candidates as $candidate) {
            if (is_string($candidate)) {
                $trimmed = trim($candidate);

                if ($trimmed !== '') {
                    return $trimmed;
                }
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{0: string, 1: bool}
     */
    protected function extractTenantAndVerification(
        OidcProviderEnum $provider,
        ?stdClass $claims,
        array $raw,
    ): array {
        if ($provider === OidcProviderEnum::Google) {
            return [
                trim((string) Arr::get($raw, 'hd', '')),
                Arr::get($raw, 'email_verified') === true,
            ];
        }

        $tenantId = (($claims?->tid ?? null) ?: null)
            ?? (Arr::get($raw, 'tid') ?: null)
            ?? (Arr::get($raw, 'tenant.id') ?: null);
        $normalizedTenantId = trim((string) ($tenantId ?? ''));

        $claimVerificationValues = [];

        if ($claims !== null) {
            if (property_exists($claims, 'xms_edov')) {
                $claimVerificationValues[] = filter_var($claims->xms_edov, FILTER_VALIDATE_BOOL);
            }

            if (property_exists($claims, 'email_verified')) {
                $claimVerificationValues[] = filter_var($claims->email_verified, FILTER_VALIDATE_BOOL);
            }
        }

        if ($claimVerificationValues !== []) {
            $emailVerified = ! in_array(false, $claimVerificationValues, true);
        } else {
            $rawVerificationValues = [];

            if (array_key_exists('xms_edov', $raw)) {
                $rawVerificationValues[] = filter_var(Arr::get($raw, 'xms_edov'), FILTER_VALIDATE_BOOL);
            }

            if (array_key_exists('email_verified', $raw)) {
                $rawVerificationValues[] = filter_var(Arr::get($raw, 'email_verified'), FILTER_VALIDATE_BOOL);
            }

            $emailVerified = $rawVerificationValues !== [] && ! in_array(false, $rawVerificationValues, true);
        }

        return [$normalizedTenantId, $emailVerified];
    }
}
