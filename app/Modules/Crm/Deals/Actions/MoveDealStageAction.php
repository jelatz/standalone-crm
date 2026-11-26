<?php

namespace App\Modules\Crm\Deals\Actions;

use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\CustomFields\Actions\ValidateEntityCustomFieldsAction;
use App\Modules\Crm\CustomFields\Enums\CustomFieldEntityTypeEnum;
use App\Modules\Crm\Deals\Data\MoveDealStageData;
use App\Modules\Crm\Deals\Models\Deal;
use App\Modules\Crm\Pipelines\Enums\PipelineStageTypeEnum;
use App\Modules\Crm\Pipelines\Models\PipelineStage;
use App\Modules\Crm\Timeline\Actions\RecordActivityAction;
use App\Modules\Crm\Timeline\Enums\ActivityTypeEnum;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class MoveDealStageAction
{
    /**
     * @var list<string>
     */
    private const array ALLOWED_CORE_UPDATES = [
        'amount_cents',
        'expected_close_date',
        'lost_reason',
        'primary_contact_id',
    ];

    private const float POSITION_STEP = 65536.0;

    private const float REBALANCE_THRESHOLD = 0.0001;

    public function __construct(
        protected ValidateEntityCustomFieldsAction $validateCustomFields,
        protected ValidateDealStageGateAction $validateStageGate,
        protected AssertDealAssociationsAction $assertAssociations,
        protected BuildDealDetailDataAction $buildDealDetail,
        protected RecordActivityAction $recordActivity,
        protected BroadcastDealBoardInvalidationAction $broadcastInvalidation,
    ) {}

    public function execute(User $actor, Deal $deal, MoveDealStageData $data): Deal
    {
        Gate::forUser($actor)->authorize('moveStage', $deal);

        foreach (array_keys($data->core_field_updates) as $coreKey) {
            if (! in_array($coreKey, self::ALLOWED_CORE_UPDATES, true)) {
                throw ValidationException::withMessages([
                    "core_field_updates.{$coreKey}" => ["Field '{$coreKey}' cannot be updated during stage move."],
                ]);
            }
        }

        if ($data->before_deal_id !== null && $data->before_deal_id === (string) $deal->id) {
            throw ValidationException::withMessages([
                'before_deal_id' => ['A deal cannot be positioned relative to itself.'],
            ]);
        }

        if ($data->after_deal_id !== null && $data->after_deal_id === (string) $deal->id) {
            throw ValidationException::withMessages([
                'after_deal_id' => ['A deal cannot be positioned relative to itself.'],
            ]);
        }

        if (
            $data->before_deal_id !== null
            && $data->after_deal_id !== null
            && $data->before_deal_id === $data->after_deal_id
        ) {
            throw ValidationException::withMessages([
                'after_deal_id' => ['Neighbor deal IDs must be distinct.'],
            ]);
        }

        return DB::transaction(function () use ($actor, $deal, $data): Deal {
            // Lock target stage before deal rows to enforce canonical stage -> deal lock ordering.
            $targetStage = PipelineStage::query()
                ->with('requiredCustomFields')
                ->where('pipeline_id', $deal->pipeline_id)
                ->whereKey($data->target_stage_id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedDeal = Deal::query()
                ->whereKey($deal->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((string) $lockedDeal->pipeline_id !== (string) $targetStage->pipeline_id) {
                throw (new ModelNotFoundException)->setModel(Deal::class, [$deal->id]);
            }

            if ($data->expected_version !== $lockedDeal->version) {
                throw $this->buildDealDetail->buildConflictException($actor, $lockedDeal);
            }

            $gateOverridden = false;
            if ($data->override_gates) {
                if (! Gate::forUser($actor)->allows('overrideGates', $lockedDeal)) {
                    throw new AuthorizationException('You are not authorized to override stage gate requirements.');
                }

                $gateOverridden = true;
            }

            $coreUpdates = $data->core_field_updates;
            $changedFields = [];

            $nextAmountCents = (int) $lockedDeal->amount_cents;
            if (array_key_exists('amount_cents', $coreUpdates)) {
                $candidateAmount = (int) $coreUpdates['amount_cents'];
                if ($candidateAmount !== $nextAmountCents) {
                    $changedFields[] = 'amount_cents';
                    $nextAmountCents = $candidateAmount;
                }
            }

            $nextExpectedCloseDate = $lockedDeal->expected_close_date?->format('Y-m-d');
            if (array_key_exists('expected_close_date', $coreUpdates)) {
                $rawDate = $coreUpdates['expected_close_date'];
                $candidateDate = $rawDate !== null && trim((string) $rawDate) !== ''
                    ? trim((string) $rawDate)
                    : null;

                if ($candidateDate !== $nextExpectedCloseDate) {
                    $changedFields[] = 'expected_close_date';
                    $nextExpectedCloseDate = $candidateDate;
                }
            }

            $nextLostReason = $lockedDeal->lost_reason;
            if (array_key_exists('lost_reason', $coreUpdates)) {
                $rawReason = $coreUpdates['lost_reason'];
                $candidateReason = $rawReason !== null && trim((string) $rawReason) !== ''
                    ? trim((string) $rawReason)
                    : null;

                if ($candidateReason !== $nextLostReason) {
                    $changedFields[] = 'lost_reason';
                    $nextLostReason = $candidateReason;
                }
            }

            $nextPrimaryContactId = $lockedDeal->primary_contact_id !== null
                ? (string) $lockedDeal->primary_contact_id
                : null;
            if (array_key_exists('primary_contact_id', $coreUpdates)) {
                $rawContactId = $coreUpdates['primary_contact_id'];
                $candidateContactId = $rawContactId !== null && trim((string) $rawContactId) !== ''
                    ? trim((string) $rawContactId)
                    : null;

                if ($candidateContactId !== $nextPrimaryContactId) {
                    $this->assertAssociations->assertCanModifyExistingContact($actor, $nextPrimaryContactId);
                }

                if ($candidateContactId !== null) {
                    $this->assertAssociations->assertContactVisible($actor, $candidateContactId);
                }

                if ($candidateContactId !== $nextPrimaryContactId) {
                    $changedFields[] = 'primary_contact_id';
                    $nextPrimaryContactId = $candidateContactId;
                }
            }

            $mergedCustomFields = $lockedDeal->custom_fields ?? [];
            if ($data->custom_field_updates !== []) {
                $validatedPartial = $this->validateCustomFields->execute(
                    entityType: CustomFieldEntityTypeEnum::Deal,
                    values: $data->custom_field_updates,
                    pipelineId: (string) $lockedDeal->pipeline_id,
                    partial: true,
                );

                $merged = array_merge($mergedCustomFields, $validatedPartial);
                $nextCustomFields = array_filter($merged, static fn (mixed $v): bool => $v !== null);

                if ($nextCustomFields !== $mergedCustomFields) {
                    $changedFields[] = 'custom_fields';
                }

                $mergedCustomFields = $nextCustomFields;
            }

            $stageChanged = (string) $lockedDeal->stage_id !== (string) $targetStage->id;

            if ($stageChanged && ! $gateOverridden) {
                $this->validateStageGate->execute(
                    targetStage: $targetStage,
                    amountCents: $nextAmountCents,
                    expectedCloseDate: $nextExpectedCloseDate,
                    primaryContactId: $nextPrimaryContactId,
                    lostReason: $nextLostReason,
                    mergedCustomFields: $mergedCustomFields,
                );
            }

            $previousStage = $stageChanged ? $lockedDeal->stage()->first() : null;

            $boardPosition = $this->computeBoardPosition(
                actor: $actor,
                lockedDeal: $lockedDeal,
                targetStage: $targetStage,
                beforeDealId: $data->before_deal_id,
                afterDealId: $data->after_deal_id,
            );

            $updateAttributes = [
                'amount_cents' => $nextAmountCents,
                'expected_close_date' => $nextExpectedCloseDate,
                'lost_reason' => $nextLostReason,
                'primary_contact_id' => $nextPrimaryContactId,
                'custom_fields' => $mergedCustomFields,
                'board_position' => $boardPosition,
                'version' => $lockedDeal->version + 1,
            ];

            if ($stageChanged) {
                $now = now();
                $updateAttributes['stage_id'] = $targetStage->id;
                $updateAttributes['stage_entered_at'] = $now;
                $updateAttributes['won_at'] = $targetStage->stage_type === PipelineStageTypeEnum::Won ? $now : null;
                $updateAttributes['lost_at'] = $targetStage->stage_type === PipelineStageTypeEnum::Lost ? $now : null;
            }

            $lockedDeal->update($updateAttributes);

            $targets = [
                'deal' => (string) $lockedDeal->id,
                'company' => (string) $lockedDeal->company_id,
            ];
            if ($nextPrimaryContactId !== null) {
                $targets['contact'] = $nextPrimaryContactId;
            }

            if ($stageChanged) {
                $fromStageName = $previousStage?->name ?? 'Previous Stage';

                $this->recordActivity->execute(
                    actor: $actor,
                    activityType: ActivityTypeEnum::StageChanged,
                    sourceType: $lockedDeal->getMorphClass(),
                    sourceId: (string) $lockedDeal->id,
                    targets: $targets,
                    summary: "Moved deal from {$fromStageName} to {$targetStage->name}",
                    metadata: [
                        'from_stage_id' => $previousStage !== null ? (string) $previousStage->id : null,
                        'from_stage_name' => $fromStageName,
                        'to_stage_id' => (string) $targetStage->id,
                        'to_stage_name' => $targetStage->name,
                        'gate_overridden' => $gateOverridden,
                        'updated_fields' => $changedFields,
                    ],
                );
            } elseif ($changedFields !== []) {
                $this->recordActivity->execute(
                    actor: $actor,
                    activityType: ActivityTypeEnum::FieldUpdated,
                    sourceType: $lockedDeal->getMorphClass(),
                    sourceId: (string) $lockedDeal->id,
                    targets: $targets,
                    summary: 'Updated deal fields: '.implode(', ', $changedFields),
                    metadata: ['changed_fields' => $changedFields],
                );
            }

            $this->broadcastInvalidation->afterCommit($lockedDeal);

            return $lockedDeal->refresh();
        });
    }

    protected function computeBoardPosition(
        User $actor,
        Deal $lockedDeal,
        PipelineStage $targetStage,
        ?string $beforeDealId,
        ?string $afterDealId,
    ): float {
        // $targetStage is already locked FOR UPDATE at the start of the transaction.
        // Lock neighbor deals in deterministic sorted UUID order to prevent AB-BA deadlocks.
        $neighborIds = array_values(array_unique(array_filter(
            [$beforeDealId, $afterDealId],
            static fn (?string $id): bool => $id !== null && $id !== (string) $lockedDeal->id,
        )));
        sort($neighborIds, SORT_STRING);

        /** @var array<string, Deal> $lockedNeighbors */
        $lockedNeighbors = [];
        foreach ($neighborIds as $neighborId) {
            $lockedNeighbors[$neighborId] = Deal::query()
                ->visibleTo($actor)
                ->where('pipeline_id', $lockedDeal->pipeline_id)
                ->where('stage_id', $targetStage->id)
                ->whereKey($neighborId)
                ->lockForUpdate()
                ->firstOrFail();
        }

        $beforeDeal = $beforeDealId !== null ? ($lockedNeighbors[$beforeDealId] ?? null) : null;
        $afterDeal = $afterDealId !== null ? ($lockedNeighbors[$afterDealId] ?? null) : null;

        if ($beforeDeal === null && $afterDeal === null) {
            $maxPosition = Deal::query()
                ->where('pipeline_id', $lockedDeal->pipeline_id)
                ->where('stage_id', $targetStage->id)
                ->whereKeyNot($lockedDeal->id)
                ->max('board_position');

            return ($maxPosition !== null ? (float) $maxPosition : 0.0) + self::POSITION_STEP;
        }

        if ($beforeDeal !== null && $afterDeal !== null) {
            $beforePos = (float) $beforeDeal->board_position;
            $afterPos = (float) $afterDeal->board_position;

            if ($beforePos > $afterPos) {
                throw ValidationException::withMessages([
                    'before_deal_id' => ['The preceding deal appears after the following deal in this stage.'],
                ]);
            }

            if (($afterPos - $beforePos) < self::REBALANCE_THRESHOLD) {
                $rebalanced = $this->rebalanceStage($lockedDeal, $targetStage);
                $beforePos = $rebalanced[(string) $beforeDeal->id];
                $afterPos = $rebalanced[(string) $afterDeal->id];
            }

            return $beforePos + (($afterPos - $beforePos) / 2.0);
        }

        if ($afterDeal !== null) {
            $afterPos = (float) $afterDeal->board_position;
            $prevMax = Deal::query()
                ->where('pipeline_id', $lockedDeal->pipeline_id)
                ->where('stage_id', $targetStage->id)
                ->whereKeyNot($lockedDeal->id)
                ->where('board_position', '<', $afterPos)
                ->max('board_position');

            $lowerBound = $prevMax !== null
                ? (float) $prevMax
                : ($afterPos > 0.0 ? 0.0 : $afterPos - self::POSITION_STEP);

            if (($afterPos - $lowerBound) < self::REBALANCE_THRESHOLD) {
                $rebalanced = $this->rebalanceStage($lockedDeal, $targetStage);
                $afterPos = $rebalanced[(string) $afterDeal->id];
                $orderedIds = array_keys($rebalanced);
                $afterIndex = array_search((string) $afterDeal->id, $orderedIds, true);
                $lowerBound = ($afterIndex !== false && $afterIndex > 0)
                    ? $rebalanced[$orderedIds[$afterIndex - 1]]
                    : 0.0;
            }

            return $lowerBound + (($afterPos - $lowerBound) / 2.0);
        }

        // $beforeDeal !== null && $afterDeal === null
        $beforePos = (float) $beforeDeal->board_position;
        $nextMin = Deal::query()
            ->where('pipeline_id', $lockedDeal->pipeline_id)
            ->where('stage_id', $targetStage->id)
            ->whereKeyNot($lockedDeal->id)
            ->where('board_position', '>', $beforePos)
            ->min('board_position');

        if ($nextMin === null) {
            return $beforePos + self::POSITION_STEP;
        }

        $upperBound = (float) $nextMin;
        if (($upperBound - $beforePos) < self::REBALANCE_THRESHOLD) {
            $rebalanced = $this->rebalanceStage($lockedDeal, $targetStage);
            $beforePos = $rebalanced[(string) $beforeDeal->id];
            $orderedIds = array_keys($rebalanced);
            $beforeIndex = array_search((string) $beforeDeal->id, $orderedIds, true);

            if ($beforeIndex === false || ! isset($orderedIds[$beforeIndex + 1])) {
                return $beforePos + self::POSITION_STEP;
            }

            $upperBound = $rebalanced[$orderedIds[$beforeIndex + 1]];
        }

        return $beforePos + (($upperBound - $beforePos) / 2.0);
    }

    /**
     * Rebalance all non-deleted deals in the target stage (excluding the deal being moved)
     * into uniform 65536 increments and return an ordered [deal_id => board_position] map.
     *
     * @return array<string, float>
     */
    protected function rebalanceStage(Deal $movingDeal, PipelineStage $targetStage): array
    {
        $stageDeals = Deal::query()
            ->where('pipeline_id', $movingDeal->pipeline_id)
            ->where('stage_id', $targetStage->id)
            ->whereKeyNot($movingDeal->id)
            ->orderBy('board_position')
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'board_position']);

        $positions = [];
        $step = 1;

        foreach ($stageDeals as $stageDeal) {
            $nextPosition = $step * self::POSITION_STEP;
            Deal::query()
                ->whereKey($stageDeal->id)
                ->update(['board_position' => $nextPosition]);

            $positions[(string) $stageDeal->id] = $nextPosition;
            $step++;
        }

        return $positions;
    }
}
