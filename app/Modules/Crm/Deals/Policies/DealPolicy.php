<?php

namespace App\Modules\Crm\Deals\Policies;

use App\Modules\Core\Rbac\Enums\PermissionEnum;
use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Deals\Models\Deal;
use App\Support\Silo\HandlesSiloPolicy;

class DealPolicy
{
    use HandlesSiloPolicy;

    public function viewAny(User $user): bool
    {
        return $this->isActiveEmployee($user)
            && $user->checkPermissionTo(PermissionEnum::DealsView->value);
    }

    public function view(User $user, Deal $deal): bool
    {
        return $this->canViewSiloRecord($user, $deal, PermissionEnum::DealsView);
    }

    public function create(User $user): bool
    {
        return $this->isActiveEmployee($user)
            && $user->checkPermissionTo(PermissionEnum::DealsCreate->value);
    }

    public function update(User $user, Deal $deal): bool
    {
        return $this->canEditSiloRecord($user, $deal, PermissionEnum::DealsUpdate);
    }

    public function delete(User $user, Deal $deal): bool
    {
        return $this->canManageSiloRecord($user, $deal, PermissionEnum::DealsDelete);
    }

    public function manageCollaborators(User $user, Deal $deal): bool
    {
        return $this->canManageSiloRecord($user, $deal, PermissionEnum::DealsUpdate);
    }

    public function moveStage(User $user, Deal $deal): bool
    {
        return $this->canEditSiloRecord($user, $deal, PermissionEnum::DealsMoveStage);
    }

    public function overrideGates(User $user, Deal $deal): bool
    {
        return $this->canEditSiloRecord($user, $deal, PermissionEnum::DealsOverrideGates);
    }
}
