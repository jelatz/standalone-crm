<?php

namespace App\Modules\Crm\Contacts\Policies;

use App\Modules\Core\Rbac\Enums\PermissionEnum;
use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Contacts\Models\Contact;
use App\Support\Silo\HandlesSiloPolicy;

class ContactPolicy
{
    use HandlesSiloPolicy;

    public function viewAny(User $user): bool
    {
        return $this->isActiveEmployee($user)
            && $user->checkPermissionTo(PermissionEnum::ContactsView->value);
    }

    public function view(User $user, Contact $contact): bool
    {
        return $this->canViewSiloRecord($user, $contact, PermissionEnum::ContactsView);
    }

    public function create(User $user): bool
    {
        return $this->isActiveEmployee($user)
            && $user->checkPermissionTo(PermissionEnum::ContactsCreate->value);
    }

    public function update(User $user, Contact $contact): bool
    {
        return $this->canEditSiloRecord($user, $contact, PermissionEnum::ContactsUpdate);
    }

    public function delete(User $user, Contact $contact): bool
    {
        return $this->canManageSiloRecord($user, $contact, PermissionEnum::ContactsDelete);
    }

    public function manageCollaborators(User $user, Contact $contact): bool
    {
        return $this->canManageSiloRecord($user, $contact, PermissionEnum::ContactsUpdate);
    }
}
