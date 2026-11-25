<?php

namespace App\Modules\Core\Users\Policies;

use App\Modules\Core\Rbac\Enums\PermissionEnum;
use App\Modules\Core\Rbac\Enums\RoleEnum;
use App\Modules\Core\Users\Enums\UserStatusEnum;
use App\Modules\Core\Users\Models\User;

class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $this->isActiveSuperadmin($actor);
    }

    public function create(User $actor): bool
    {
        return $this->isActiveSuperadmin($actor);
    }

    protected function isActiveSuperadmin(User $actor): bool
    {
        return ! $actor->trashed()
            && $actor->status === UserStatusEnum::Active
            && $actor->hasRole(RoleEnum::Superadmin->value)
            && $actor->can(PermissionEnum::AdminUsers->value);
    }
}
