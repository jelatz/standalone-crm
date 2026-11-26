<?php

namespace App\Modules\Crm\CustomFields\Data;

use App\Modules\Crm\CustomFields\Enums\CustomFieldEntityTypeEnum;
use App\Modules\Crm\CustomFields\Enums\CustomFieldTypeEnum;
use App\Modules\Crm\CustomFields\Models\CustomFieldDefinition;
use Closure;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;
use Spatie\LaravelData\Support\Validation\ValidationContext;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class UpsertCustomFieldDefinitionData extends Data
{
    /**
     * @param  array<int, array<string, mixed>|string>|Optional|null  $options
     * @param  array<string, mixed>|Optional  $validation_rules
     */
    public function __construct(
        public CustomFieldEntityTypeEnum|Optional $entity_type = new Optional,
        public string|Optional|null $pipeline_id = new Optional,
        public string|Optional $key = new Optional,
        public string|Optional $label = new Optional,
        public CustomFieldTypeEnum|Optional $field_type = new Optional,
        public array|Optional|null $options = new Optional,
        public array|Optional $validation_rules = new Optional,
        public bool|Optional $is_filterable = new Optional,
        public bool|Optional $is_active = new Optional,
        public int|Optional $sort_order = new Optional,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(ValidationContext $context): array
    {
        $routeModel = request()->route('customFieldDefinition');
        $existing = $routeModel instanceof CustomFieldDefinition ? $routeModel : null;
        $isCreate = $existing === null;

        /** @var array<string, mixed> $payload */
        $payload = is_array($context->fullPayload) ? $context->fullPayload : [];

        $effectiveEntityType = is_string($payload['entity_type'] ?? null)
            ? $payload['entity_type']
            : $existing?->entity_type?->value;

        $effectiveFieldType = is_string($payload['field_type'] ?? null)
            ? $payload['field_type']
            : $existing?->field_type?->value;

        return [
            'entity_type' => [
                $isCreate ? 'required' : 'sometimes',
                Rule::enum(CustomFieldEntityTypeEnum::class),
            ],
            'pipeline_id' => [
                'sometimes',
                'nullable',
                'uuid',
                'exists:pipelines,id',
                static function (string $attribute, mixed $value, Closure $fail) use ($effectiveEntityType): void {
                    if ($value !== null && $effectiveEntityType !== CustomFieldEntityTypeEnum::Deal->value) {
                        $fail('Only deal custom fields can be scoped to a pipeline.');
                    }
                },
            ],
            'key' => [
                $isCreate ? 'required' : 'sometimes',
                'string',
                'max:64',
                'regex:/^[a-z][a-z0-9_]{0,63}$/',
                Rule::unique('custom_field_definitions', 'key')
                    ->where('entity_type', (string) $effectiveEntityType)
                    ->ignore($existing?->id),
            ],
            'label' => [
                $isCreate ? 'required' : 'sometimes',
                'string',
                'min:1',
                'max:120',
            ],
            'field_type' => [
                $isCreate ? 'required' : 'sometimes',
                Rule::enum(CustomFieldTypeEnum::class),
            ],
            'options' => [
                'sometimes',
                'nullable',
                'array',
                static function (string $attribute, mixed $value, Closure $fail) use ($effectiveFieldType): void {
                    $requiresOptions = in_array($effectiveFieldType, [
                        CustomFieldTypeEnum::Select->value,
                        CustomFieldTypeEnum::MultiSelect->value,
                    ], true);

                    if ($requiresOptions && (! is_array($value) || $value === [])) {
                        $fail('Select and multi-select custom fields require at least one option.');

                        return;
                    }

                    if (! is_array($value)) {
                        return;
                    }

                    foreach ($value as $option) {
                        $validString = is_string($option) && trim($option) !== '';
                        $validObject = is_array($option) && is_string($option['value'] ?? null) && trim($option['value']) !== '';

                        if (! $validString && ! $validObject) {
                            $fail('Each custom field option must be a non-empty string or object with a value.');

                            return;
                        }
                    }
                },
            ],
            'validation_rules' => ['sometimes', 'array'],
            'validation_rules.required' => ['sometimes', 'boolean'],
            'validation_rules.min' => ['sometimes', 'numeric'],
            'validation_rules.max' => ['sometimes', 'numeric'],
            'validation_rules.min_length' => ['sometimes', 'integer', 'min:0'],
            'validation_rules.max_length' => ['sometimes', 'integer', 'min:1'],
            'is_filterable' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
        ];
    }
}
