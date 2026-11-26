<?php

namespace App\Modules\Crm\Pipelines\Actions;

use App\Modules\Crm\CustomFields\Enums\CustomFieldEntityTypeEnum;
use App\Modules\Crm\CustomFields\Models\CustomFieldDefinition;
use App\Modules\Crm\Pipelines\Data\UpsertPipelineStageData;
use App\Modules\Crm\Pipelines\Models\Pipeline;
use App\Modules\Crm\Pipelines\Models\PipelineStage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Optional;

class UpsertPipelineStageAction
{
    public function execute(
        Pipeline $pipeline,
        UpsertPipelineStageData $data,
        ?PipelineStage $stage = null,
    ): PipelineStage {
        if ($stage !== null && (string) $stage->pipeline_id !== (string) $pipeline->id) {
            abort(404);
        }

        return DB::transaction(function () use ($pipeline, $data, $stage): PipelineStage {
            $name = ! $data->name instanceof Optional ? $data->name : $stage?->name;
            $stageType = ! $data->stage_type instanceof Optional ? $data->stage_type : $stage?->stage_type;
            $winProbability = ! $data->win_probability instanceof Optional ? $data->win_probability : $stage?->win_probability;

            if ($name === null || $stageType === null || $winProbability === null) {
                throw ValidationException::withMessages([
                    'name' => ['Stage name, stage type, and win probability are required.'],
                ]);
            }

            $rottingDays = ! $data->rotting_days instanceof Optional
                ? $data->rotting_days
                : $stage?->rotting_days;

            $requiredCoreFields = ! $data->required_core_fields instanceof Optional
                ? array_values(array_unique(array_map('strval', $data->required_core_fields)))
                : ($stage?->required_core_fields ?? []);

            foreach ($requiredCoreFields as $field) {
                if (! in_array($field, UpsertPipelineStageData::ALLOWED_REQUIRED_CORE_FIELDS, true)) {
                    throw ValidationException::withMessages([
                        'required_core_fields' => ["Core field '{$field}' is not allowed as a stage requirement."],
                    ]);
                }
            }

            $sortOrder = ! $data->sort_order instanceof Optional
                ? $data->sort_order
                : ($stage?->sort_order ?? 0);

            $shouldSyncCustomFields = $stage === null || ! $data->required_custom_field_ids instanceof Optional;
            /** @var list<string> $requiredCustomFieldIds */
            $requiredCustomFieldIds = ! $data->required_custom_field_ids instanceof Optional
                ? array_values(array_unique(array_map('strval', $data->required_custom_field_ids)))
                : [];

            if ($shouldSyncCustomFields && $requiredCustomFieldIds !== []) {
                $validCount = CustomFieldDefinition::query()
                    ->whereIn('id', $requiredCustomFieldIds)
                    ->where('entity_type', CustomFieldEntityTypeEnum::Deal->value)
                    ->where('is_active', true)
                    ->where(function ($sub) use ($pipeline): void {
                        $sub->whereNull('pipeline_id')->orWhere('pipeline_id', $pipeline->id);
                    })
                    ->count();

                if ($validCount !== count($requiredCustomFieldIds)) {
                    throw ValidationException::withMessages([
                        'required_custom_field_ids' => ['One or more custom field definitions are invalid or belong to another pipeline.'],
                    ]);
                }
            }

            $attributes = [
                'pipeline_id' => $pipeline->id,
                'name' => trim($name),
                'stage_type' => $stageType,
                'win_probability' => $winProbability,
                'rotting_days' => $rottingDays,
                'required_core_fields' => $requiredCoreFields,
                'sort_order' => $sortOrder,
            ];

            if ($stage === null) {
                $persisted = PipelineStage::query()->create($attributes);
            } else {
                $stage->update($attributes);
                $persisted = $stage->refresh();
            }

            if ($shouldSyncCustomFields) {
                $persisted->requiredCustomFields()->sync($requiredCustomFieldIds);
            }

            return $persisted->load('requiredCustomFields');
        });
    }
}
