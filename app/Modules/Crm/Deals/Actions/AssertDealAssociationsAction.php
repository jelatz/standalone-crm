<?php

namespace App\Modules\Crm\Deals\Actions;

use App\Modules\Core\Users\Enums\UserStatusEnum;
use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Companies\Models\Company;
use App\Modules\Crm\Contacts\Models\Contact;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AssertDealAssociationsAction
{
    public function assertCompanyVisible(User $actor, string $companyId): void
    {
        $visible = Gate::forUser($actor)->allows('viewAny', Company::class)
            && Company::query()
                ->visibleTo($actor)
                ->whereKey($companyId)
                ->exists();

        if (! $visible) {
            throw new AuthorizationException('You are not authorized to link a deal to the selected company.');
        }
    }

    public function assertContactVisible(User $actor, string $contactId): void
    {
        $visible = Gate::forUser($actor)->allows('viewAny', Contact::class)
            && Contact::query()
                ->visibleTo($actor)
                ->whereKey($contactId)
                ->exists();

        if (! $visible) {
            throw new AuthorizationException('You are not authorized to link a deal to the selected contact.');
        }
    }

    public function assertCanModifyExistingCompany(User $actor, ?string $existingCompanyId): void
    {
        if ($existingCompanyId === null) {
            return;
        }

        $existingCompany = Company::query()->find($existingCompanyId);
        if ($existingCompany === null || ! Gate::forUser($actor)->allows('view', $existingCompany)) {
            throw new AuthorizationException('You are not authorized to unlink or replace an inaccessible company on this deal.');
        }
    }

    public function assertCanModifyExistingContact(User $actor, ?string $existingContactId): void
    {
        if ($existingContactId === null) {
            return;
        }

        $existingContact = Contact::query()->find($existingContactId);
        if ($existingContact === null || ! Gate::forUser($actor)->allows('view', $existingContact)) {
            throw new AuthorizationException('You are not authorized to unlink or replace an inaccessible primary contact on this deal.');
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

        $visibleCount = Gate::forUser($actor)->allows('viewAny', Contact::class)
            ? Contact::query()
                ->visibleTo($actor)
                ->whereIn('id', $uniqueIds)
                ->count()
            : 0;

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
