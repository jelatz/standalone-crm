<?php

namespace App\Modules\Core\Rbac\Actions;

use App\Modules\Core\Rbac\Enums\CollaboratorAccessLevel;
use App\Modules\Core\Rbac\Enums\CollaboratorRoleLabel;
use App\Modules\Core\Rbac\Models\EntityCollaborator;
use App\Modules\Core\Users\Models\User;
use Illuminate\Database\Eloquent\Model;

class GrantEntityCollaboratorAction
{
    public function execute(
        Model $collaboratable,
        User $user,
        CollaboratorAccessLevel $accessLevel,
        CollaboratorRoleLabel $roleLabel,
        User $grantedBy,
    ): EntityCollaborator {
        $collaboratableType = $collaboratable->getMorphClass();
        $collaboratableId = (string) $collaboratable->getKey();

        EntityCollaborator::query()->upsert(
            [
                [
                    'collaboratable_type' => $collaboratableType,
                    'collaboratable_id' => $collaboratableId,
                    'user_id' => (string) $user->id,
                    'access_level' => $accessLevel->value,
                    'role_label' => $roleLabel->value,
                    'granted_by_id' => (string) $grantedBy->id,
                ],
            ],
            ['collaboratable_type', 'collaboratable_id', 'user_id'],
            ['access_level', 'role_label', 'granted_by_id'],
        );

        return EntityCollaborator::query()
            ->where('collaboratable_type', $collaboratableType)
            ->where('collaboratable_id', $collaboratableId)
            ->where('user_id', (string) $user->id)
            ->firstOrFail();
    }
}
