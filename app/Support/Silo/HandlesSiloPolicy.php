<?php

namespace App\Support\Silo;

use App\Modules\Core\Rbac\Enums\CollaboratorAccessLevel;
use App\Modules\Core\Rbac\Enums\PermissionEnum;
use App\Modules\Core\Rbac\Models\EntityCollaborator;
use App\Modules\Core\Users\Enums\UserStatusEnum;
use App\Modules\Core\Users\Models\User;
use Illuminate\Database\Eloquent\Model;

trait HandlesSiloPolicy
{
    /**
     * Ensure only persisted, non-deleted, active employees can satisfy policy checks.
     */
    protected function isActiveEmployee(User $user): bool
    {
        return $user->exists
            && ! $user->trashed()
            && $user->status === UserStatusEnum::Active;
    }

    protected function canViewSiloRecord(User $user, Model $record, PermissionEnum $permission): bool
    {
        if (! $this->isActiveEmployee($user) || ! $user->checkPermissionTo($permission->value)) {
            return false;
        }

        return $record::query()
            ->whereKey($record->getKey())
            ->visibleTo($user)
            ->exists();
    }

    protected function canEditSiloRecord(User $user, Model $record, PermissionEnum $permission): bool
    {
        return $this->authorizeSiloMutation(
            user: $user,
            record: $record,
            permission: $permission,
            allowedCollaboratorLevels: [
                CollaboratorAccessLevel::Editor->value,
                CollaboratorAccessLevel::Manager->value,
            ],
        );
    }

    protected function canManageSiloRecord(User $user, Model $record, PermissionEnum $permission): bool
    {
        return $this->authorizeSiloMutation(
            user: $user,
            record: $record,
            permission: $permission,
            allowedCollaboratorLevels: [
                CollaboratorAccessLevel::Manager->value,
            ],
        );
    }

    /**
     * @param  list<string>  $allowedCollaboratorLevels
     */
    private function authorizeSiloMutation(
        User $user,
        Model $record,
        PermissionEnum $permission,
        array $allowedCollaboratorLevels,
    ): bool {
        if (! $this->isActiveEmployee($user) || ! $user->checkPermissionTo($permission->value)) {
            return false;
        }

        if (method_exists($record, 'trashed') && $record->trashed()) {
            return false;
        }

        $scope = app(VisibleScopeResolver::class)->for($user);

        if ($scope->isUnrestrictedAdmin) {
            return true;
        }

        if ($scope->siloUserIds === []) {
            return false;
        }

        $ownerColumn = method_exists($record, 'siloOwnerColumn') ? $record->siloOwnerColumn() : 'owner_id';
        $ownerId = $record->getAttribute($ownerColumn);

        if ($ownerId !== null && in_array((string) $ownerId, $scope->siloUserIds, true)) {
            return true;
        }

        $accountManagerColumn = method_exists($record, 'accountManagerColumn') ? $record->accountManagerColumn() : null;
        if ($accountManagerColumn !== null) {
            $accountManagerId = $record->getAttribute($accountManagerColumn);

            if ($accountManagerId !== null && in_array((string) $accountManagerId, $scope->siloUserIds, true)) {
                return true;
            }
        }

        return EntityCollaborator::query()
            ->where('collaboratable_type', $record->getMorphClass())
            ->where('collaboratable_id', (string) $record->getKey())
            ->where('user_id', (string) $user->id)
            ->whereIn('access_level', $allowedCollaboratorLevels)
            ->exists();
    }
}
