<?php

namespace App\Modules\Projects\Handoffs\Actions;

use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Deals\Models\Deal;
use App\Modules\Crm\Pipelines\Enums\PipelineStageTypeEnum;
use App\Modules\Crm\Timeline\Actions\RecordActivityAction;
use App\Modules\Crm\Timeline\Enums\ActivityTypeEnum;
use App\Modules\Projects\Handoffs\Data\SubmitHandoffData;
use App\Modules\Projects\Handoffs\Enums\DealHandoffStatusEnum;
use App\Modules\Projects\Handoffs\Models\DealHandoff;
use App\Modules\Projects\Handoffs\Support\HandoffPayloadNormalizer;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SubmitDealHandoffAction
{
    public function __construct(
        protected HandoffPayloadNormalizer $normalizer,
        protected RecordActivityAction $recordActivity,
        protected BroadcastHandoffStatusAction $broadcastStatus,
    ) {}

    public function execute(User $actor, Deal $deal, SubmitHandoffData $data): DealHandoff
    {
        Gate::forUser($actor)->authorize('submit', [DealHandoff::class, $deal]);

        $normalized = $this->normalizer->normalizeSubmission($data);

        return DB::transaction(function () use ($actor, $deal, $normalized): DealHandoff {
            $lockedDeal = Deal::query()
                ->whereKey($deal->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedDeal->load('stage');

            if ($lockedDeal->stage?->stage_type !== PipelineStageTypeEnum::Won) {
                throw ValidationException::withMessages([
                    'deal_id' => ['Deal must be in a Won stage before submitting a handoff.'],
                ]);
            }

            $activeExists = DealHandoff::query()
                ->where('deal_id', $lockedDeal->id)
                ->whereIn('status', [
                    DealHandoffStatusEnum::PendingReview->value,
                    DealHandoffStatusEnum::ChangesRequested->value,
                    DealHandoffStatusEnum::Approved->value,
                ])
                ->exists();

            if ($activeExists) {
                throw ValidationException::withMessages([
                    'deal_id' => ['An active handoff already exists for this deal.'],
                ]);
            }

            try {
                $handoff = DB::transaction(static function () use ($actor, $lockedDeal, $normalized): DealHandoff {
                    return DealHandoff::query()->create([
                        'deal_id' => $lockedDeal->id,
                        'company_id' => $lockedDeal->company_id,
                        'submitted_by_id' => $actor->id,
                        'assigned_am_id' => $normalized['assigned_am_id'],
                        'status' => DealHandoffStatusEnum::PendingReview,
                        'scope_notes_json' => $normalized['scope_notes_json'],
                        'handoff_checklist' => $normalized['handoff_checklist'],
                        'target_kickoff_date' => $normalized['target_kickoff_date'],
                        'submitted_at' => now(),
                    ]);
                });
            } catch (UniqueConstraintViolationException $e) {
                throw ValidationException::withMessages([
                    'deal_id' => ['An active handoff already exists for this deal.'],
                ]);
            } catch (QueryException $e) {
                if ((string) $e->getCode() === '23505') {
                    throw ValidationException::withMessages([
                        'deal_id' => ['An active handoff already exists for this deal.'],
                    ]);
                }

                throw $e;
            }

            $this->recordActivity->execute(
                actor: $actor,
                activityType: ActivityTypeEnum::HandoffSubmitted,
                sourceType: $handoff->getMorphClass(),
                sourceId: (string) $handoff->id,
                targets: [
                    'deal' => (string) $lockedDeal->id,
                    'company' => (string) $lockedDeal->company_id,
                ],
                summary: "Submitted deal handoff for {$lockedDeal->title}",
                metadata: [
                    'handoff_id' => (string) $handoff->id,
                    'target_kickoff_date' => $normalized['target_kickoff_date'],
                ],
            );

            $this->broadcastStatus->afterCommitToReviewers($handoff);

            return $handoff->refresh();
        });
    }
}
