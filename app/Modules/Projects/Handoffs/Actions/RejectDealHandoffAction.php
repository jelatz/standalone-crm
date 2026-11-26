<?php

namespace App\Modules\Projects\Handoffs\Actions;

use App\Modules\Core\Users\Models\User;
use App\Modules\Projects\Handoffs\Data\ReviewHandoffData;
use App\Modules\Projects\Handoffs\Enums\DealHandoffStatusEnum;
use App\Modules\Projects\Handoffs\Models\DealHandoff;

class RejectDealHandoffAction
{
    public function __construct(
        protected ApplyHandoffReviewDecisionAction $applyDecision,
    ) {}

    public function execute(User $actor, DealHandoff $handoff, ReviewHandoffData $data): DealHandoff
    {
        return $this->applyDecision->execute(
            actor: $actor,
            handoff: $handoff,
            data: $data,
            policyAbility: 'reject',
            targetStatus: DealHandoffStatusEnum::Rejected,
            emptyFeedbackMessage: 'Review feedback is required when rejecting a handoff.',
        );
    }
}
