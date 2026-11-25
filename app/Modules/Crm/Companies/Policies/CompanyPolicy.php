<?php

namespace App\Modules\Crm\Companies\Policies;

use App\Modules\Core\Rbac\Enums\PermissionEnum;
use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Companies\Models\Company;
use App\Support\Silo\HandlesSiloPolicy;

class CompanyPolicy
{
    use HandlesSiloPolicy;

    public function viewAny(User $user): bool
    {
        return $this->isActiveEmployee($user)
            && $user->checkPermissionTo(PermissionEnum::CompaniesView->value);
    }

    public function view(User $user, Company $company): bool
    {
        return $this->canViewSiloRecord($user, $company, PermissionEnum::CompaniesView);
    }

    public function create(User $user): bool
    {
        return $this->isActiveEmployee($user)
            && $user->checkPermissionTo(PermissionEnum::CompaniesCreate->value);
    }

    public function update(User $user, Company $company): bool
    {
        return $this->canEditSiloRecord($user, $company, PermissionEnum::CompaniesUpdate);
    }

    public function delete(User $user, Company $company): bool
    {
        return $this->canManageSiloRecord($user, $company, PermissionEnum::CompaniesDelete);
    }

    public function manageCollaborators(User $user, Company $company): bool
    {
        return $this->canManageSiloRecord($user, $company, PermissionEnum::CompaniesUpdate);
    }
}
