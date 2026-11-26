<?php

namespace App\Modules\Projects\Projects\Policies;

use App\Modules\Core\Rbac\Enums\PermissionEnum;
use App\Modules\Core\Users\Models\User;
use App\Modules\Projects\Projects\Models\Project;
use App\Support\Silo\HandlesSiloPolicy;

class ProjectPolicy
{
    use HandlesSiloPolicy;

    public function viewAny(User $user): bool
    {
        return $this->isActiveEmployee($user)
            && $user->checkPermissionTo(PermissionEnum::ProjectsView->value);
    }

    public function view(User $user, Project $project): bool
    {
        return $this->canViewSiloRecord($user, $project, PermissionEnum::ProjectsView);
    }

    public function create(User $user): bool
    {
        return $this->isActiveEmployee($user)
            && $user->checkPermissionTo(PermissionEnum::ProjectsCreate->value);
    }

    public function update(User $user, Project $project): bool
    {
        return $this->canEditSiloRecord($user, $project, PermissionEnum::ProjectsUpdate);
    }

    public function delete(User $user, Project $project): bool
    {
        return $this->canManageSiloRecord($user, $project, PermissionEnum::ProjectsDelete);
    }

    public function manageStages(User $user, Project $project): bool
    {
        return $this->canEditSiloRecord($user, $project, PermissionEnum::ProjectsUpdate);
    }

    public function manageCollaborators(User $user, Project $project): bool
    {
        return $this->canManageSiloRecord($user, $project, PermissionEnum::ProjectsUpdate);
    }
}
