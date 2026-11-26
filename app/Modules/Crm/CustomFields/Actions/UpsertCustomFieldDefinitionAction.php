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
            $entityType = ! $data->entity_type instanceof Optional
                ? $data->entity_type
                : $definition?->entity_type;

            $pipelineId = ! $data->pipeline_id instanceof Optional
                ? $data->pipeline_id
                : $definition?->pipeline_id;

            $key = ! $data->key instanceof Optional
                ? $data->key
                : $definition?->key;

            $label = ! $data->label instanceof Optional
                ? $data->label
                : $definition?->label;

            $fieldType = ! $data->field_type instanceof Optional
                ? $data->field_type
                : $definition?->field_type;

            $options = ! $data->options instanceof Optional
                ? $data->options
                : $definition?->options;

            $validationRules = ! $data->validation_rules instanceof Optional
                ? $data->validation_rules
                : ($definition?->validation_rules ?? []);

            $isFilterable = ! $data->is_filterable instanceof Optional
                ? $data->is_filterable
                : ($definition?->is_filterable ?? true);

            $isActive = ! $data->is_active instanceof Optional
                ? $data->is_active
                : ($definition?->is_active ?? true);

            $sortOrder = ! $data->sort_order instanceof Optional
                ? $data->sort_order
                : ($definition?->sort_order ?? 0);

            if ($entityType === null || $key === null || $label === null || $fieldType === null) {
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

            $duplicateExists = CustomFieldDefinition::query()
                ->where('entity_type', $entityType->value)
                ->where('key', $key)
                ->when($definition !== null, fn ($q) => $q->whereKeyNot($definition->id))
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

            if ($definition === null) {
                return CustomFieldDefinition::query()->create($attributes);
            }

            $definition->update($attributes);

            return $definition->refresh();
        });
    }
}
