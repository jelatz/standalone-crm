<?php

namespace App\Modules\Crm\Leads\Policies;

use App\Modules\Core\Rbac\Enums\PermissionEnum;
use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Leads\Models\Lead;
use App\Support\Silo\HandlesSiloPolicy;

class LeadPolicy
{
    use HandlesSiloPolicy;

    public function viewAny(User $user): bool
    {
        return $this->isActiveEmployee($user)
            && $user->checkPermissionTo(PermissionEnum::LeadsView->value);
    }

    public function view(User $user, Lead $lead): bool
    {
        return $this->canViewSiloRecord($user, $lead, PermissionEnum::LeadsView);
    }

    public function create(User $user): bool
    {
        return $this->isActiveEmployee($user)
            && $user->checkPermissionTo(PermissionEnum::LeadsCreate->value);
    }

    public function update(User $user, Lead $lead): bool
    {
        return $this->canEditSiloRecord($user, $lead, PermissionEnum::LeadsUpdate);
    }

    public function delete(User $user, Lead $lead): bool
    {
        return $this->canManageSiloRecord($user, $lead, PermissionEnum::LeadsDelete);
    }

    public function convert(User $user, Lead $lead): bool
    {
        return $this->canEditSiloRecord($user, $lead, PermissionEnum::LeadsConvert);
    }

    public function convertAny(User $user): bool
    {
        return $this->isActiveEmployee($user)
            && $user->checkPermissionTo(PermissionEnum::LeadsConvert->value);
    }

    public function manageCollaborators(User $user, Lead $lead): bool
    {
        return $this->canManageSiloRecord($user, $lead, PermissionEnum::LeadsUpdate);
    }
}
