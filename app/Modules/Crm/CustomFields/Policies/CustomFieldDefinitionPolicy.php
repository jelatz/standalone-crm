<?php

namespace App\Modules\Crm\CustomFields\Policies;

use App\Modules\Core\Rbac\Enums\PermissionEnum;
use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\CustomFields\Models\CustomFieldDefinition;
use App\Support\Silo\HandlesSiloPolicy;

class CustomFieldDefinitionPolicy
{
    use HandlesSiloPolicy;

    public function viewAny(User $user): bool
    {
        return $this->isActiveEmployee($user)
            && $user->checkPermissionTo(PermissionEnum::CustomFieldsView->value);
    }

    public function view(User $user, CustomFieldDefinition $definition): bool
    {
        return $this->isActiveEmployee($user)
            && $user->checkPermissionTo(PermissionEnum::CustomFieldsView->value);
    }

    public function create(User $user): bool
    {
        return $this->isActiveEmployee($user)
            && $user->checkPermissionTo(PermissionEnum::CustomFieldsManage->value);
    }

    public function update(User $user, CustomFieldDefinition $definition): bool
    {
        return $this->isActiveEmployee($user)
            && $user->checkPermissionTo(PermissionEnum::CustomFieldsManage->value);
    }

    public function delete(User $user, CustomFieldDefinition $definition): bool
    {
        return $this->isActiveEmployee($user)
            && $user->checkPermissionTo(PermissionEnum::CustomFieldsManage->value);
    }
}
