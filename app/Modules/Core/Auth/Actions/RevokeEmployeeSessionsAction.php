<?php

namespace App\Modules\Core\Auth\Actions;

use App\Modules\Core\Users\Models\User;
use Illuminate\Support\Facades\Cache;

class RevokeEmployeeSessionsAction
{
    public function execute(User $user): void
    {
        Cache::forever(
            self::cacheKey((string) $user->id),
            (int) now()->getPreciseTimestamp(6),
        );
    }

    public static function cacheKey(string $userId): string
    {
        return "auth:session_revoked_at:{$userId}";
    }
}
