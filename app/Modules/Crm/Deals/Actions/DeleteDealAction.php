<?php

namespace App\Modules\Crm\Deals\Actions;

use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Deals\Models\Deal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DeleteDealAction
{
    public function __construct(
        protected BroadcastDealBoardInvalidationAction $broadcastInvalidation,
    ) {}

    public function execute(User $actor, Deal $deal): void
    {
        Gate::forUser($actor)->authorize('delete', $deal);

        DB::transaction(function () use ($deal): void {
            $lockedDeal = Deal::query()
                ->whereKey($deal->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->broadcastInvalidation->afterCommit($lockedDeal);
            $lockedDeal->delete();
        });
    }
}
