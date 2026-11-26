<?php

namespace App\Modules\Crm\Deals\Actions;

use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\CustomFields\Actions\ValidateEntityCustomFieldsAction;
use App\Modules\Crm\CustomFields\Enums\CustomFieldEntityTypeEnum;
use App\Modules\Crm\Deals\Data\CreateDealData;
use App\Modules\Crm\Deals\Data\DealStakeholderInputData;
use App\Modules\Crm\Deals\Models\Deal;
use App\Modules\Crm\Pipelines\Enums\PipelineStageTypeEnum;
use App\Modules\Crm\Pipelines\Models\Pipeline;
use App\Modules\Crm\Pipelines\Models\PipelineStage;
use App\Modules\Crm\Timeline\Actions\RecordActivityAction;
use App\Modules\Crm\Timeline\Enums\ActivityTypeEnum;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CreateDealAction
{
    public function __construct(
        protected ValidateEntityCustomFieldsAction $validateCustomFields,
        protected ValidateDealStageGateAction $validateStageGate,
        protected AssertDealAssociationsAction $assertAssociations,
        protected RecordActivityAction $recordActivity,
        protected BroadcastDealBoardInvalidationAction $broadcastInvalidation,
    ) {}

    public function execute(User $actor, CreateDealData $data): Deal
    {
        Gate::forUser($actor)->authorize('create', Deal::class);

        $title = trim($data->title);
        if ($title === '') {
            throw ValidationException::withMessages([
                'title' => ['Deal title is required.'],
            ]);
        }

        $pipeline = $data->pipeline_id !== null && trim($data->pipeline_id) !== ''
            ? Pipeline::query()->findOrFail(trim($data->pipeline_id))
            : Pipeline::query()
                ->where('is_active', true)
                ->orderByDesc('is_default')
                ->orderBy('sort_order')
                ->firstOrFail();

        if (! $pipeline->is_active) {
            throw ValidationException::withMessages([
                'pipeline_id' => ['Selected pipeline is not active.'],
            ]);
        }

        if ($data->stage_id !== null && trim($data->stage_id) !== '') {
            $stage = PipelineStage::query()
                ->with('requiredCustomFields')
                ->where('pipeline_id', $pipeline->id)
                ->find(trim($data->stage_id));

            if ($stage === null) {
                throw ValidationException::withMessages([
                    'stage_id' => ['The selected stage does not belong to the chosen pipeline.'],
                ]);
            }
        } else {
            $stage = $pipeline->stages()
                ->with('requiredCustomFields')
                ->orderBy('sort_order')
                ->firstOrFail();
        }

        $primaryContactId = $data->primary_contact_id !== null && trim($data->primary_contact_id) !== ''
            ? trim($data->primary_contact_id)
            : null;

        $accountManagerId = $data->account_manager_id !== null && trim($data->account_manager_id) !== ''
            ? trim($data->account_manager_id)
            : null;

        $expectedCloseDate = $data->expected_close_date !== null && trim($data->expected_close_date) !== ''
            ? trim($data->expected_close_date)
            : null;

        $lostReason = $data->lost_reason !== null && trim($data->lost_reason) !== ''
            ? trim($data->lost_reason)
            : null;

        if ($accountManagerId !== null) {
            $this->assertAssociations->assertActiveAccountManager($accountManagerId);
        }

        $requiredStageFieldIds = $stage->requiredCustomFields
            ->where('is_active', true)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();

        $validatedCustomFields = $this->validateCustomFields->execute(
            entityType: CustomFieldEntityTypeEnum::Deal,
            values: $data->custom_fields,
            pipelineId: (string) $pipeline->id,
            requiredDefinitionIds: [],
            partial: false,
        );

        // Enforce unified stage-gate check if creating directly into a gated stage
        if (($stage->required_core_fields ?? []) !== [] || $requiredStageFieldIds !== []) {
            $this->validateStageGate->execute(
                targetStage: $stage,
                amountCents: $data->amount_cents,
                expectedCloseDate: $expectedCloseDate,
                primaryContactId: $primaryContactId,
                lostReason: $lostReason,
                mergedCustomFields: $validatedCustomFields,
            );
        }

        return DB::transaction(function () use (
            $actor,
            $data,
            $pipeline,
            $stage,
            $title,
            $primaryContactId,
            $accountManagerId,
            $expectedCloseDate,
            $lostReason,
            $validatedCustomFields,
        ): Deal {
            $this->assertAssociations->assertCompanyVisible($actor, trim($data->company_id));

            if ($primaryContactId !== null) {
                $this->assertAssociations->assertContactVisible($actor, $primaryContactId);
            }

            if ($data->stakeholders !== null && $data->stakeholders !== []) {
                $stakeholderIds = array_map(
                    static fn (DealStakeholderInputData $item): string => $item->contact_id,
                    $data->stakeholders,
                );
                $this->assertAssociations->assertContactsVisible($actor, $stakeholderIds);
            }

            $stageLock = PipelineStage::query()
                ->where('pipeline_id', $pipeline->id)
                ->whereKey($stage->id)
                ->lockForUpdate()
                ->firstOrFail();

            $maxPosition = Deal::query()
                ->where('pipeline_id', $pipeline->id)
                ->where('stage_id', $stageLock->id)
                ->max('board_position');
            $boardPosition = ($maxPosition !== null ? (float) $maxPosition : 0.0) + 65536.0;

            $now = now();
            $wonAt = $stageLock->stage_type === PipelineStageTypeEnum::Won ? $now : null;
            $lostAt = $stageLock->stage_type === PipelineStageTypeEnum::Lost ? $now : null;

            $deal = Deal::query()->create([
                'company_id' => trim($data->company_id),
                'primary_contact_id' => $primaryContactId,
                'pipeline_id' => $pipeline->id,
                'stage_id' => $stage->id,
                'owner_id' => $actor->id,
                'account_manager_id' => $accountManagerId,
                'title' => $title,
                'amount_cents' => $data->amount_cents,
                'currency' => strtoupper(trim($data->currency)),
                'expected_close_date' => $expectedCloseDate,
                'stage_entered_at' => $now,
                'won_at' => $wonAt,
                'lost_at' => $lostAt,
                'lost_reason' => $lostReason,
                'board_position' => $boardPosition,
                'custom_fields' => $validatedCustomFields,
                'version' => 1,
            ]);

            if ($data->stakeholders !== null && $data->stakeholders !== []) {
                $syncPayload = [];
                foreach ($data->stakeholders as $item) {
                    $syncPayload[$item->contact_id] = [
                        'stakeholder_role' => $item->stakeholder_role->value,
                    ];
                }
                $deal->contacts()->sync($syncPayload);
            }

            $targets = [
                'deal' => (string) $deal->id,
                'company' => (string) $deal->company_id,
            ];
            if ($deal->primary_contact_id !== null) {
                $targets['contact'] = (string) $deal->primary_contact_id;
            }

            $this->recordActivity->execute(
                actor: $actor,
                activityType: ActivityTypeEnum::DealCreated,
                sourceType: $deal->getMorphClass(),
                sourceId: (string) $deal->id,
                targets: $targets,
                summary: "Created deal {$deal->title}",
                metadata: [
                    'pipeline_id' => (string) $deal->pipeline_id,
                    'stage_id' => (string) $deal->stage_id,
                    'amount_cents' => $deal->amount_cents,
                    'currency' => $deal->currency,
                ],
            );

            $this->broadcastInvalidation->afterCommit($deal);

            return $deal->refresh();
        });
    }
}
