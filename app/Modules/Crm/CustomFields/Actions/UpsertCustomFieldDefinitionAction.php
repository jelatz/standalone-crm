<?php

namespace App\Modules\Crm\CustomFields\Actions;

use App\Modules\Crm\CustomFields\Data\UpsertCustomFieldDefinitionData;
use App\Modules\Crm\CustomFields\Enums\CustomFieldEntityTypeEnum;
use App\Modules\Crm\CustomFields\Enums\CustomFieldTypeEnum;
use App\Modules\Crm\CustomFields\Models\CustomFieldDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Optional;

class UpsertCustomFieldDefinitionAction
{
    public function execute(
        UpsertCustomFieldDefinitionData $data,
        ?CustomFieldDefinition $definition = null,
    ): CustomFieldDefinition {
        return DB::transaction(function () use ($data, $definition): CustomFieldDefinition {
            $lockedDefinition = $definition !== null
                ? CustomFieldDefinition::query()->whereKey($definition->id)->lockForUpdate()->firstOrFail()
                : null;

            if ($lockedDefinition !== null) {
                $immutabilityErrors = [];
                if (! $data->entity_type instanceof Optional && $data->entity_type !== $lockedDefinition->entity_type) {
                    $immutabilityErrors['entity_type'] = ['Entity type cannot be changed once a custom field definition is created.'];
                }
                if (! $data->key instanceof Optional && trim($data->key) !== $lockedDefinition->key) {
                    $immutabilityErrors['key'] = ['Custom field key cannot be changed once created.'];
                }
                if ($immutabilityErrors !== []) {
                    throw ValidationException::withMessages($immutabilityErrors);
                }
            }

            $entityType = ! $data->entity_type instanceof Optional
                ? $data->entity_type
                : $lockedDefinition?->entity_type;

            $pipelineId = ! $data->pipeline_id instanceof Optional
                ? ($data->pipeline_id !== null && trim($data->pipeline_id) !== '' ? trim($data->pipeline_id) : null)
                : $lockedDefinition?->pipeline_id;

            $key = ! $data->key instanceof Optional
                ? trim($data->key)
                : $lockedDefinition?->key;

            $label = ! $data->label instanceof Optional
                ? $data->label
                : $lockedDefinition?->label;

            $fieldType = ! $data->field_type instanceof Optional
                ? $data->field_type
                : $lockedDefinition?->field_type;

            $options = ! $data->options instanceof Optional
                ? $data->options
                : $lockedDefinition?->options;

            $validationRules = ! $data->validation_rules instanceof Optional
                ? $data->validation_rules
                : ($lockedDefinition?->validation_rules ?? []);

            $isFilterable = ! $data->is_filterable instanceof Optional
                ? $data->is_filterable
                : ($lockedDefinition?->is_filterable ?? true);

            $isActive = ! $data->is_active instanceof Optional
                ? $data->is_active
                : ($lockedDefinition?->is_active ?? true);

            $sortOrder = ! $data->sort_order instanceof Optional
                ? $data->sort_order
                : ($lockedDefinition?->sort_order ?? 0);

            if ($entityType === null || $key === null || $key === '' || $label === null || $fieldType === null) {
                throw ValidationException::withMessages([
                    'entity_type' => ['Entity type, key, label, and field type are required.'],
                ]);
            }

            if ($pipelineId !== null && $entityType !== CustomFieldEntityTypeEnum::Deal) {
                throw ValidationException::withMessages([
                    'pipeline_id' => ['Only deal custom fields can be scoped to a pipeline.'],
                ]);
            }

            if (in_array($fieldType, [CustomFieldTypeEnum::Select, CustomFieldTypeEnum::MultiSelect], true) && ($options === null || $options === [])) {
                throw ValidationException::withMessages([
                    'options' => ['Select and multi-select custom fields require at least one option.'],
                ]);
            }

            if ($lockedDefinition !== null) {
                /** @var list<string> $requiredPipelineIds */
                $requiredPipelineIds = $lockedDefinition->requiredStages()
                    ->pluck('pipeline_stages.pipeline_id')
                    ->map(static fn (mixed $id): string => (string) $id)
                    ->unique()
                    ->values()
                    ->all();

                if ($requiredPipelineIds !== []) {
                    $stageErrors = [];
                    if (! $isActive) {
                        $stageErrors['is_active'] = ['Cannot deactivate a custom field that is required by one or more pipeline stages.'];
                    }

                    if ($pipelineId !== null) {
                        $incompatible = array_filter(
                            $requiredPipelineIds,
                            static fn (string $pid): bool => $pid !== (string) $pipelineId,
                        );
                        if ($incompatible !== []) {
                            $stageErrors['pipeline_id'] = ['Cannot scope this custom field to the selected pipeline because it is required by stages in another pipeline.'];
                        }
                    }

                    if ($stageErrors !== []) {
                        throw ValidationException::withMessages($stageErrors);
                    }
                }
            }

            $duplicateExists = CustomFieldDefinition::query()
                ->where('entity_type', $entityType->value)
                ->where('key', $key)
                ->when($lockedDefinition !== null, fn ($q) => $q->whereKeyNot($lockedDefinition->id))
                ->exists();

            if ($duplicateExists) {
                throw ValidationException::withMessages([
                    'key' => ['A custom field with this key already exists for this entity type.'],
                ]);
            }

            $attributes = [
                'entity_type' => $entityType,
                'pipeline_id' => $pipelineId,
                'key' => $key,
                'label' => $label,
                'field_type' => $fieldType,
                'options' => $options,
                'validation_rules' => $validationRules,
                'is_filterable' => $isFilterable,
                'is_active' => $isActive,
                'sort_order' => $sortOrder,
            ];

            if ($lockedDefinition === null) {
                return CustomFieldDefinition::query()->create($attributes);
            }

            $lockedDefinition->update($attributes);

            return $lockedDefinition->refresh();
        });
    }
}
