<?php

namespace App\Modules\Crm\Shared\Actions;

use App\Modules\Core\Rbac\Actions\GrantEntityCollaboratorAction;
use App\Modules\Core\Rbac\Actions\RevokeEntityCollaboratorAction;
use App\Modules\Core\Rbac\Data\EntityCollaboratorData;
use App\Modules\Core\Rbac\Data\GrantCollaboratorData;
use App\Modules\Core\Users\Enums\UserStatusEnum;
use App\Modules\Core\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

class ManageCrmCollaboratorAction
{
    public function __construct(
        protected GrantEntityCollaboratorAction $grantCollaborator,
        protected RevokeEntityCollaboratorAction $revokeCollaborator,
    ) {}

    public function grant(User $actor, Model $entity, GrantCollaboratorData $data): EntityCollaboratorData
    {
        Gate::forUser($actor)->authorize('manageCollaborators', $entity);

        $targetUser = User::query()
            ->where('status', UserStatusEnum::Active->value)
            ->findOrFail($data->user_id);

        $collaborator = $this->grantCollaborator->execute(
            collaboratable: $entity,
            user: $targetUser,
            accessLevel: $data->access_level,
            roleLabel: $data->role_label,
            grantedBy: $actor,
        );

        $collaborator->load(['user:id,name,email', 'grantedBy:id,name']);

        return EntityCollaboratorData::fromModel($collaborator);
    }

    public function revoke(User $actor, Model $entity, string $targetUserId): void
    {
        Gate::forUser($actor)->authorize('manageCollaborators', $entity);

        $targetUser = User::withTrashed()->findOrFail($targetUserId);

        $this->revokeCollaborator->execute($entity, $targetUser);
    }
}
