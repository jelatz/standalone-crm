<?php

namespace App\Modules\Crm\Pipelines\Policies;

use App\Modules\Core\Rbac\Enums\PermissionEnum;
use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Pipelines\Models\Pipeline;
use App\Support\Silo\HandlesSiloPolicy;

class PipelinePolicy
{
    use HandlesSiloPolicy;

    public function viewAny(User $user): bool
    {
        return $this->isActiveEmployee($user)
            && $user->checkPermissionTo(PermissionEnum::PipelinesView->value);
    }

    public function view(User $user, Pipeline $pipeline): bool
    {
        return $this->isActiveEmployee($user)
            && $user->checkPermissionTo(PermissionEnum::PipelinesView->value);
    }

    public function create(User $user): bool
    {
        return $this->isActiveEmployee($user)
            && $user->checkPermissionTo(PermissionEnum::PipelinesManage->value);
    }

    public function update(User $user, Pipeline $pipeline): bool
    {
        return $this->isActiveEmployee($user)
            && $user->checkPermissionTo(PermissionEnum::PipelinesManage->value);
    }

    public function delete(User $user, Pipeline $pipeline): bool
    {
        return $this->isActiveEmployee($user)
            && $user->checkPermissionTo(PermissionEnum::PipelinesManage->value);
    }
}
