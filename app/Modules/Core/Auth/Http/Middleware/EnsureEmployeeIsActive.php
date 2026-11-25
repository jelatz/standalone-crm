<?php

namespace App\Modules\Core\Auth\Http\Middleware;

use App\Modules\Core\Auth\Actions\RevokeEmployeeSessionsAction;
use App\Modules\Core\Users\Enums\UserStatusEnum;
use App\Modules\Core\Users\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class EnsureEmployeeIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $isInactive = $user->trashed() || $user->status !== UserStatusEnum::Active;
        $isRevoked = false;

        if (! $isInactive && $request->hasSession()) {
            $revokedAt = Cache::get(RevokeEmployeeSessionsAction::cacheKey((string) $user->id));

            if ($revokedAt !== null) {
                $authenticatedAt = (int) $request->session()->get('auth.authenticated_at', 0);
                $isRevoked = $authenticatedAt <= (int) $revokedAt;
            }
        }

        if (! $isInactive && ! $isRevoked) {
            return $next($request);
        }

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        if ($request->expectsJson() || $request->is('api/*', 'broadcasting/*')) {
            return response()->json([
                'message' => 'Employee account is inactive or session has been revoked.',
            ], 403);
        }

        return redirect('/login')->withErrors([
            'sso' => 'Employee account is inactive or session has been revoked.',
        ]);
    }
}
