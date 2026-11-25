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
        return EntityCollaborator::query()->updateOrCreate(
            [
                'collaboratable_type' => $collaboratable->getMorphClass(),
                'collaboratable_id' => (string) $collaboratable->getKey(),
                'user_id' => $user->id,
            ],
            [
                'access_level' => $accessLevel,
                'role_label' => $roleLabel,
                'granted_by_id' => $grantedBy->id,
            ],
        );
    }
}
