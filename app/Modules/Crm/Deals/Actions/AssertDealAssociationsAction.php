<?php

namespace App\Modules\Crm\Deals\Actions;

use App\Modules\Core\Users\Enums\UserStatusEnum;
use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Companies\Models\Company;
use App\Modules\Crm\Contacts\Models\Contact;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class AssertDealAssociationsAction
{
    public function assertCompanyVisible(User $actor, string $companyId): void
    {
        $visible = Company::query()
            ->visibleTo($actor)
            ->whereKey($companyId)
            ->exists();

        if (! $visible) {
            throw new AuthorizationException('You are not authorized to link a deal to the selected company.');
        }
    }

    public function assertContactVisible(User $actor, string $contactId): void
    {
        $visible = Contact::query()
            ->visibleTo($actor)
            ->whereKey($contactId)
            ->exists();

        if (! $visible) {
            throw new AuthorizationException('You are not authorized to link a deal to the selected contact.');
        }
    }

    /**
     * @param  list<string>  $contactIds
     */
    public function assertContactsVisible(User $actor, array $contactIds): void
    {
        $uniqueIds = array_values(array_unique(array_filter($contactIds, static fn (string $id): bool => $id !== '')));
        if ($uniqueIds === []) {
            return;
        }

        $visibleCount = Contact::query()
            ->visibleTo($actor)
            ->whereIn('id', $uniqueIds)
            ->count();

        if ($visibleCount !== count($uniqueIds)) {
            throw new AuthorizationException('You are not authorized to attach one or more selected contacts to this deal.');
        }
    }

    public function assertActiveAccountManager(string $accountManagerId): void
    {
        $exists = User::query()
            ->whereKey($accountManagerId)
            ->where('status', UserStatusEnum::Active->value)
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'account_manager_id' => ['The selected account manager must be an active employee.'],
            ]);
        }
    }
}
