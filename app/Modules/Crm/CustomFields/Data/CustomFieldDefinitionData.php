<?php

namespace App\Modules\Crm\CustomFields\Data;

use App\Modules\Crm\CustomFields\Enums\CustomFieldEntityTypeEnum;
use App\Modules\Crm\CustomFields\Enums\CustomFieldTypeEnum;
use App\Modules\Crm\CustomFields\Models\CustomFieldDefinition;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class CustomFieldDefinitionData extends Data
{
    /**
     * @param  array<int, array<string, mixed>>|null  $options
     * @param  array<string, mixed>  $validation_rules
     */
    public function __construct(
        public string $id,
        public CustomFieldEntityTypeEnum $entity_type,
        public ?string $pipeline_id,
        public string $key,
        public string $label,
        public CustomFieldTypeEnum $field_type,
        public ?array $options,
        public array $validation_rules,
        public bool $is_filterable,
        public bool $is_active,
        public int $sort_order,
    ) {}

    public static function fromModel(CustomFieldDefinition $definition): self
    {
        return new self(
            id: (string) $definition->id,
            entity_type: $definition->entity_type,
            pipeline_id: $definition->pipeline_id !== null ? (string) $definition->pipeline_id : null,
            key: $definition->key,
            label: $definition->label,
            field_type: $definition->field_type,
            options: $definition->options,
            validation_rules: $definition->validation_rules ?? [],
            is_filterable: $definition->is_filterable,
            is_active: $definition->is_active,
            sort_order: $definition->sort_order,
        );
    }
}
