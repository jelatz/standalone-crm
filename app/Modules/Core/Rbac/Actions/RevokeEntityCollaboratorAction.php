<?php

namespace App\Modules\Core\Rbac\Actions;

use App\Modules\Core\Rbac\Models\EntityCollaborator;
use App\Modules\Core\Users\Models\User;
use Illuminate\Database\Eloquent\Model;

class RevokeEntityCollaboratorAction
{
    public function execute(Model $collaboratable, User $user): void
    {
        EntityCollaborator::query()
            ->where('collaboratable_type', $collaboratable->getMorphClass())
            ->where('collaboratable_id', (string) $collaboratable->getKey())
            ->where('user_id', $user->id)
            ->delete();
    }
}
