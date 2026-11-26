<?php

namespace App\Modules\Crm\Deals\Actions;

use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Deals\Data\DealStakeholderData;
use App\Modules\Crm\Deals\Data\DealStakeholderInputData;
use App\Modules\Crm\Deals\Data\SyncDealContactsData;
use App\Modules\Crm\Deals\Models\Deal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class SyncDealContactsAction
{
    public function __construct(
        protected AssertDealAssociationsAction $assertAssociations,
    ) {}

    /**
     * @return list<DealStakeholderData>
     */
    public function execute(User $actor, Deal $deal, SyncDealContactsData $data): array
    {
        Gate::forUser($actor)->authorize('update', $deal);

        return DB::transaction(function () use ($actor, $deal, $data): array {
            $lockedDeal = Deal::query()
                ->whereKey($deal->id)
                ->lockForUpdate()
                ->firstOrFail();

            $contactIds = array_map(
                static fn (DealStakeholderInputData $item): string => $item->contact_id,
                $data->contacts,
            );

            $this->assertAssociations->assertContactsVisible($actor, $contactIds);

            $syncPayload = [];
            foreach ($data->contacts as $item) {
                $syncPayload[$item->contact_id] = [
                    'stakeholder_role' => $item->stakeholder_role->value,
                ];
            }

            $lockedDeal->contacts()->sync($syncPayload);

            return $lockedDeal->contacts()
                ->visibleTo($actor)
                ->orderBy('contacts.last_name')
                ->orderBy('contacts.first_name')
                ->get()
                ->map(static fn ($contact): DealStakeholderData => DealStakeholderData::fromContact($contact))
                ->values()
                ->all();
        });
    }
}
