<?php

namespace App\Modules\Projects\Handoffs\Actions;

use App\Modules\Core\Users\Models\User;
use App\Modules\Projects\Handoffs\Data\ReviewHandoffData;
use App\Modules\Projects\Handoffs\Enums\DealHandoffStatusEnum;
use App\Modules\Projects\Handoffs\Models\DealHandoff;
use App\Modules\Projects\Handoffs\Support\DealHandoffStateMachine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class ApplyHandoffReviewDecisionAction
{
    public function __construct(
        private readonly BroadcastHandoffStatusAction $broadcastStatus,
    ) {}

    public function execute(
        User $actor,
        DealHandoff $handoff,
        ReviewHandoffData $data,
        string $policyAbility,
        DealHandoffStatusEnum $targetStatus,
        string $emptyFeedbackMessage,
    ): DealHandoff {
        Gate::forUser($actor)->authorize($policyAbility, $handoff);

        $feedback = trim($data->review_feedback);
        if ($feedback === '') {
            throw ValidationException::withMessages([
                'review_feedback' => [$emptyFeedbackMessage],
            ]);
        }

        return DB::transaction(function () use ($actor, $handoff, $targetStatus, $feedback): DealHandoff {
            $lockedHandoff = DealHandoff::query()
                ->whereKey($handoff->id)
                ->lockForUpdate()
                ->firstOrFail();

            DealHandoffStateMachine::assertCanTransition($lockedHandoff->status, $targetStatus);

            $lockedHandoff->forceFill([
                'status' => $targetStatus,
                'reviewed_by_id' => $actor->id,
                'reviewed_at' => now(),
                'review_feedback' => $feedback,
            ])->save();

            $this->broadcastStatus->afterCommitToStakeholders($lockedHandoff);

            return $lockedHandoff->refresh();
        });
    }
}
