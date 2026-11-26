<?php

namespace App\Modules\Crm\Deals\Actions;

use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Deals\Data\DealDetailData;
use App\Modules\Crm\Deals\Models\Deal;
use App\Support\Exceptions\OptimisticLockConflictException;
use Illuminate\Support\Facades\Gate;

class BuildDealDetailDataAction
{
    public function execute(User $viewer, Deal $deal): DealDetailData
    {
        $deal->load([
            'company:id,name,domain,owner_id,account_manager_id',
            'primaryContact:id,first_name,last_name,full_name,primary_email,owner_id',
            'pipeline:id,name',
            'stage:id,name,stage_type,win_probability',
            'owner:id,name',
            'accountManager:id,name',
        ]);

        $canViewCompany = $deal->company !== null
            && Gate::forUser($viewer)->allows('view', $deal->company);

        $canViewPrimaryContact = $deal->primaryContact !== null
            && Gate::forUser($viewer)->allows('view', $deal->primaryContact);

        return DealDetailData::fromModel($deal, $canViewCompany, $canViewPrimaryContact);
    }

    public function buildConflictException(User $viewer, Deal $lockedDeal): OptimisticLockConflictException
    {
        $freshData = $this->execute($viewer, $lockedDeal);

        return new OptimisticLockConflictException(
            currentVersion: (int) $lockedDeal->version,
            entity: $freshData->toArray(),
        );
    }
}
