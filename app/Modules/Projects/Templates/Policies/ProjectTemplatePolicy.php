<?php

namespace App\Modules\Projects\Templates\Policies;

use App\Modules\Core\Rbac\Enums\PermissionEnum;
use App\Modules\Core\Users\Models\User;
use App\Modules\Projects\Templates\Models\ProjectTemplate;
use App\Support\Silo\HandlesSiloPolicy;

class ProjectTemplatePolicy
{
    use HandlesSiloPolicy;

    public function viewAny(User $user): bool
    {
        return $this->isActiveEmployee($user)
            && (
                $user->checkPermissionTo(PermissionEnum::HandoffsApprove->value)
                || $user->checkPermissionTo(PermissionEnum::ProjectsManageTemplates->value)
            );
    }

    public function view(User $user, ProjectTemplate $template): bool
    {
        return $template->exists && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->isActiveEmployee($user)
            && $user->checkPermissionTo(PermissionEnum::ProjectsManageTemplates->value);
    }

    public function update(User $user, ProjectTemplate $template): bool
    {
        return $template->exists && $this->create($user);
    }

    public function delete(User $user, ProjectTemplate $template): bool
    {
        return $template->exists && $this->create($user);
    }
}
