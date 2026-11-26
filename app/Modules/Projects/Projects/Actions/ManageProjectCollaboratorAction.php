<?php

namespace App\Modules\Projects\Projects\Actions;

use App\Modules\Core\Rbac\Actions\GrantEntityCollaboratorAction;
use App\Modules\Core\Rbac\Actions\RevokeEntityCollaboratorAction;
use App\Modules\Core\Rbac\Data\EntityCollaboratorData;
use App\Modules\Core\Rbac\Data\GrantCollaboratorData;
use App\Modules\Core\Users\Enums\UserStatusEnum;
use App\Modules\Core\Users\Models\User;
use App\Modules\Projects\Projects\Models\Project;
use Illuminate\Support\Facades\Gate;

class ManageProjectCollaboratorAction
{
    public function __construct(
        protected GrantEntityCollaboratorAction $grantCollaborator,
        protected RevokeEntityCollaboratorAction $revokeCollaborator,
    ) {}

    public function grant(User $actor, Project $project, GrantCollaboratorData $data): EntityCollaboratorData
    {
        Gate::forUser($actor)->authorize('manageCollaborators', $project);

        $targetUser = User::query()
            ->where('status', UserStatusEnum::Active->value)
            ->findOrFail($data->user_id);

        $collaborator = $this->grantCollaborator->execute(
            collaboratable: $project,
            user: $targetUser,
            accessLevel: $data->access_level,
            roleLabel: $data->role_label,
            grantedBy: $actor,
        );

        $collaborator->load(['user:id,name,email', 'grantedBy:id,name']);

        return EntityCollaboratorData::fromModel($collaborator);
    }

    public function revoke(User $actor, Project $project, string $targetUserId): void
    {
        Gate::forUser($actor)->authorize('manageCollaborators', $project);

        $targetUser = User::withTrashed()->findOrFail($targetUserId);

        $this->revokeCollaborator->execute($project, $targetUser);
    }
}
