<?php

namespace App\Modules\Crm\Deals\Actions;

use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\CustomFields\Actions\ValidateEntityCustomFieldsAction;
use App\Modules\Crm\CustomFields\Enums\CustomFieldEntityTypeEnum;
use App\Modules\Crm\Deals\Data\UpdateDealData;
use App\Modules\Crm\Deals\Models\Deal;
use App\Modules\Crm\Pipelines\Enums\PipelineStageTypeEnum;
use App\Modules\Crm\Pipelines\Models\PipelineStage;
use App\Modules\Crm\Timeline\Actions\RecordActivityAction;
use App\Modules\Crm\Timeline\Enums\ActivityTypeEnum;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Optional;

class UpdateDealAction
{
    public function __construct(
        protected ValidateEntityCustomFieldsAction $validateCustomFields,
        protected ValidateDealStageGateAction $validateStageGate,
        protected AssertDealAssociationsAction $assertAssociations,
        protected BuildDealDetailDataAction $buildDealDetail,
        protected RecordActivityAction $recordActivity,
        protected BroadcastDealBoardInvalidationAction $broadcastInvalidation,
    ) {}

    public function execute(User $actor, Deal $deal, UpdateDealData $data): Deal
    {
        Gate::forUser($actor)->authorize('update', $deal);

        if (! $data->title instanceof Optional && trim($data->title) === '') {
            throw ValidationException::withMessages([
                'title' => ['Deal title cannot be empty.'],
            ]);
        }

        return DB::transaction(function () use ($actor, $deal, $data): Deal {
            $lockedDeal = Deal::query()
                ->whereKey($deal->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($data->expected_version !== $lockedDeal->version) {
                throw $this->buildDealDetail->buildConflictException($actor, $lockedDeal);
            }

            $attributes = [];
            $changedFields = [];

            $nextCompanyId = (string) $lockedDeal->company_id;
            if (! $data->company_id instanceof Optional) {
                $candidateCompanyId = trim($data->company_id);
                if ($candidateCompanyId !== (string) $lockedDeal->company_id) {
                    $this->assertAssociations->assertCompanyVisible($actor, $candidateCompanyId);
                    $attributes['company_id'] = $candidateCompanyId;
                    $changedFields[] = 'company_id';
                    $nextCompanyId = $candidateCompanyId;
                }
            }

            $nextPrimaryContactId = $lockedDeal->primary_contact_id !== null ? (string) $lockedDeal->primary_contact_id : null;
            if (! $data->primary_contact_id instanceof Optional) {
                $candidateContactId = $data->primary_contact_id !== null && trim($data->primary_contact_id) !== ''
                    ? trim($data->primary_contact_id)
                    : null;

                if ($candidateContactId !== null && $candidateContactId !== $nextPrimaryContactId) {
                    $this->assertAssociations->assertContactVisible($actor, $candidateContactId);
                }

                if ($candidateContactId !== $nextPrimaryContactId) {
                    $attributes['primary_contact_id'] = $candidateContactId;
                    $changedFields[] = 'primary_contact_id';
                    $nextPrimaryContactId = $candidateContactId;
                }
            }

            if (! $data->account_manager_id instanceof Optional) {
                $accountManagerId = $data->account_manager_id !== null && trim($data->account_manager_id) !== ''
                    ? trim($data->account_manager_id)
                    : null;

                if ($accountManagerId !== null && $accountManagerId !== $lockedDeal->account_manager_id) {
                    $this->assertAssociations->assertActiveAccountManager($accountManagerId);
                }

                if ($accountManagerId !== $lockedDeal->account_manager_id) {
                    $attributes['account_manager_id'] = $accountManagerId;
                    $changedFields[] = 'account_manager_id';
                }
            }

            if (! $data->title instanceof Optional) {
                $title = trim($data->title);
                if ($title !== $lockedDeal->title) {
                    $attributes['title'] = $title;
                    $changedFields[] = 'title';
                }
            }

            $nextAmountCents = (int) $lockedDeal->amount_cents;
            if (! $data->amount_cents instanceof Optional && $data->amount_cents !== $lockedDeal->amount_cents) {
                $attributes['amount_cents'] = $data->amount_cents;
                $changedFields[] = 'amount_cents';
                $nextAmountCents = $data->amount_cents;
            }

            if (! $data->currency instanceof Optional) {
                $currency = strtoupper(trim($data->currency));
                if ($currency !== $lockedDeal->currency) {
                    $attributes['currency'] = $currency;
                    $changedFields[] = 'currency';
                }
            }

            $nextExpectedCloseDate = $lockedDeal->expected_close_date?->format('Y-m-d');
            if (! $data->expected_close_date instanceof Optional) {
                $candidateDate = $data->expected_close_date !== null && trim($data->expected_close_date) !== ''
                    ? trim($data->expected_close_date)
                    : null;

                if ($candidateDate !== $nextExpectedCloseDate) {
                    $attributes['expected_close_date'] = $candidateDate;
                    $changedFields[] = 'expected_close_date';
                    $nextExpectedCloseDate = $candidateDate;
                }
            }

            $nextLostReason = $lockedDeal->lost_reason;
            if (! $data->lost_reason instanceof Optional) {
                $candidateReason = $data->lost_reason !== null && trim($data->lost_reason) !== ''
                    ? trim($data->lost_reason)
                    : null;

                if ($candidateReason !== $nextLostReason) {
                    $attributes['lost_reason'] = $candidateReason;
                    $changedFields[] = 'lost_reason';
                    $nextLostReason = $candidateReason;
                }
            }

            $mergedCustomFields = $lockedDeal->custom_fields ?? [];
            if (! $data->custom_fields instanceof Optional) {
                $validatedPartial = $this->validateCustomFields->execute(
                    entityType: CustomFieldEntityTypeEnum::Deal,
                    values: $data->custom_fields,
                    pipelineId: (string) $lockedDeal->pipeline_id,
                    partial: true,
                );

                $merged = array_merge($mergedCustomFields, $validatedPartial);
                $nextCustomFields = array_filter($merged, static fn (mixed $v): bool => $v !== null);

                if ($nextCustomFields !== $mergedCustomFields) {
                    $attributes['custom_fields'] = $nextCustomFields;
                    $changedFields[] = 'custom_fields';
                }

                $mergedCustomFields = $nextCustomFields;
            }

            $stageTransitioned = false;
            $previousStage = null;
            $targetStage = null;
            $gateOverridden = false;

            if (! $data->stage_id instanceof Optional && trim($data->stage_id) !== (string) $lockedDeal->stage_id) {
                Gate::forUser($actor)->authorize('moveStage', $lockedDeal);

                $targetStage = PipelineStage::query()
                    ->with('requiredCustomFields')
                    ->where('pipeline_id', $lockedDeal->pipeline_id)
                    ->findOrFail(trim($data->stage_id));

                if ($data->override_gates) {
                    if (! Gate::forUser($actor)->allows('overrideGates', $lockedDeal)) {
                        throw new AuthorizationException('You are not authorized to override stage gate requirements.');
                    }

                    $gateOverridden = true;
                } else {
                    $this->validateStageGate->execute(
                        targetStage: $targetStage,
                        amountCents: $nextAmountCents,
                        expectedCloseDate: $nextExpectedCloseDate,
                        primaryContactId: $nextPrimaryContactId,
                        lostReason: $nextLostReason,
                        mergedCustomFields: $mergedCustomFields,
                    );
                }

                $previousStage = $lockedDeal->stage()->first();

                PipelineStage::query()->whereKey($targetStage->id)->lockForUpdate()->first();
                $maxPosition = Deal::query()
                    ->where('pipeline_id', $lockedDeal->pipeline_id)
                    ->where('stage_id', $targetStage->id)
                    ->whereKeyNot($lockedDeal->id)
                    ->max('board_position');

                $now = now();
                $attributes['stage_id'] = $targetStage->id;
                $attributes['stage_entered_at'] = $now;
                $attributes['board_position'] = ($maxPosition !== null ? (float) $maxPosition : 0.0) + 65536.0;
                $attributes['won_at'] = $targetStage->stage_type === PipelineStageTypeEnum::Won ? $now : null;
                $attributes['lost_at'] = $targetStage->stage_type === PipelineStageTypeEnum::Lost ? $now : null;

                $stageTransitioned = true;
            }

            if ($attributes !== []) {
                $attributes['version'] = $lockedDeal->version + 1;
                $lockedDeal->update($attributes);

                $targets = [
                    'deal' => (string) $lockedDeal->id,
                    'company' => $nextCompanyId,
                ];
                if ($nextPrimaryContactId !== null) {
                    $targets['contact'] = $nextPrimaryContactId;
                }

                if ($changedFields !== []) {
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

                if ($stageTransitioned && $targetStage !== null) {
                    $fromName = $previousStage?->name ?? 'Previous Stage';

                    $this->recordActivity->execute(
                        actor: $actor,
                        activityType: ActivityTypeEnum::StageChanged,
                        sourceType: $lockedDeal->getMorphClass(),
                        sourceId: (string) $lockedDeal->id,
                        targets: $targets,
                        summary: "Moved deal from {$fromName} to {$targetStage->name}",
                        metadata: [
                            'from_stage_id' => $previousStage !== null ? (string) $previousStage->id : null,
                            'from_stage_name' => $fromName,
                            'to_stage_id' => (string) $targetStage->id,
                            'to_stage_name' => $targetStage->name,
                            'gate_overridden' => $gateOverridden,
                        ],
                    );
                }

                $this->broadcastInvalidation->afterCommit($lockedDeal);
            }

            return $lockedDeal->refresh();
        });
    }
}
