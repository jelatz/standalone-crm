<?php

namespace App\Modules\Projects\Handoffs\Actions;

use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Timeline\Actions\RecordActivityAction;
use App\Modules\Crm\Timeline\Enums\ActivityTypeEnum;
use App\Modules\Projects\Handoffs\Data\SubmitHandoffData;
use App\Modules\Projects\Handoffs\Enums\DealHandoffStatusEnum;
use App\Modules\Projects\Handoffs\Models\DealHandoff;
use App\Modules\Projects\Handoffs\Support\DealHandoffStateMachine;
use App\Modules\Projects\Handoffs\Support\HandoffPayloadNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ResubmitDealHandoffAction
{
    public function __construct(
        protected HandoffPayloadNormalizer $normalizer,
        protected RecordActivityAction $recordActivity,
        protected BroadcastHandoffStatusAction $broadcastStatus,
    ) {}

    public function execute(User $actor, DealHandoff $handoff, SubmitHandoffData $data): DealHandoff
    {
        Gate::forUser($actor)->authorize('resubmit', $handoff);

        $normalized = $this->normalizer->normalizeSubmission($data);

        return DB::transaction(function () use ($actor, $handoff, $normalized): DealHandoff {
            $lockedHandoff = DealHandoff::query()
                ->whereKey($handoff->id)
                ->lockForUpdate()
                ->firstOrFail();

            DealHandoffStateMachine::assertCanTransition(
                $lockedHandoff->status,
                DealHandoffStatusEnum::PendingReview,
            );

            $lockedHandoff->forceFill([
                'status' => DealHandoffStatusEnum::PendingReview,
                'scope_notes_json' => $normalized['scope_notes_json'],
                'handoff_checklist' => $normalized['handoff_checklist'],
                'target_kickoff_date' => $normalized['target_kickoff_date'],
                'assigned_am_id' => $normalized['assigned_am_id'],
                'submitted_at' => now(),
            ])->save();

            $this->recordActivity->execute(
                actor: $actor,
                activityType: ActivityTypeEnum::HandoffSubmitted,
                sourceType: $lockedHandoff->getMorphClass(),
                sourceId: (string) $lockedHandoff->id,
                targets: [
                    'deal' => (string) $lockedHandoff->deal_id,
                    'company' => (string) $lockedHandoff->company_id,
                ],
                summary: 'Resubmitted deal handoff for review',
                metadata: [
                    'handoff_id' => (string) $lockedHandoff->id,
                    'resubmitted' => true,
                    'target_kickoff_date' => $normalized['target_kickoff_date'],
                ],
            );

            $this->broadcastStatus->afterCommitToReviewers($lockedHandoff);

            return $lockedHandoff->refresh();
        });
    }
}
