<?php

namespace App\Modules\Core\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Auth\Actions\AuthenticatePasswordUserAction;
use App\Modules\Core\Auth\Data\LoginRequestData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class AuthController extends Controller
{
    public function showLogin(): InertiaResponse
    {
        $demoSuperadmin = app()->environment('local')
            ? [
                'email' => mb_strtolower(trim((string) config('auth.seed_superadmin.email', 'superadmin@example.com'))),
                'password' => (string) config('auth.seed_superadmin.password', 'SuperAdmin123!'),
            ]
            : null;

        return Inertia::render('core/pages/LoginPage', [
            'demoSuperadmin' => $demoSuperadmin,
        ]);
    }

    public function login(
        LoginRequestData $data,
        Request $request,
        AuthenticatePasswordUserAction $authenticateUser,
    ): RedirectResponse {
        $user = $authenticateUser->execute($data);

        Auth::login($user, $data->remember);

        if ($request->hasSession()) {
            $request->session()->regenerate();
            $request->session()->put('auth.authenticated_at', (int) now()->getPreciseTimestamp(6));
        }

        return redirect()->intended('/app');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return redirect('/login');
    }
}
