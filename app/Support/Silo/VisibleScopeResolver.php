<?php

namespace App\Support\Silo;

use App\Modules\Core\Rbac\Enums\RoleEnum;
use App\Modules\Core\Users\Enums\UserStatusEnum;
use App\Modules\Core\Users\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class VisibleScopeResolver
{
    public function for(User $viewer): VisibleScope
    {
        if (! $viewer->exists || $viewer->trashed() || $viewer->status !== UserStatusEnum::Active) {
            return new VisibleScope(
                viewer: $viewer,
                isUnrestrictedAdmin: false,
                siloUserIds: [],
            );
        }

        if ($viewer->hasAnyRole([RoleEnum::Superadmin->value, RoleEnum::Admin->value])) {
            return new VisibleScope(
                viewer: $viewer,
                isUnrestrictedAdmin: true,
                siloUserIds: [(string) $viewer->id],
            );
        }

        /** @var list<string> $descendants */
        $descendants = Cache::remember(
            self::cacheKey((string) $viewer->id),
            now()->addMinutes(30),
            static function () use ($viewer): array {
                return DB::table('user_hierarchy_closures')
                    ->where('ancestor_id', $viewer->id)
                    ->where('descendant_id', '!=', $viewer->id)
                    ->pluck('descendant_id')
                    ->map(static fn (mixed $id): string => (string) $id)
                    ->values()
                    ->all();
            },
        );

        return new VisibleScope(
            viewer: $viewer,
            isUnrestrictedAdmin: false,
            siloUserIds: array_values(array_unique([(string) $viewer->id, ...$descendants])),
        );
    }

    /**
     * @param  array<int, string>  $userIds
     */
    public function invalidateForUsers(array $userIds): void
    {
        foreach (array_unique($userIds) as $userId) {
            if ($userId !== '') {
                Cache::forget(self::cacheKey($userId));
            }
        }
    }

    public static function cacheKey(string $userId): string
    {
        return "silo:descendants:{$userId}";
    }
}
