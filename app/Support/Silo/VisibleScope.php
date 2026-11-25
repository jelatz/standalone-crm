<?php

namespace App\Support\Silo;

use App\Modules\Core\Users\Models\User;

final readonly class VisibleScope
{
    /**
     * @param  list<string>  $siloUserIds
     */
    public function __construct(
        public User $viewer,
        public bool $isUnrestrictedAdmin,
        public array $siloUserIds,
    ) {}

    public function includesUser(string $userId): bool
    {
        return $this->isUnrestrictedAdmin || in_array($userId, $this->siloUserIds, true);
    }
}
