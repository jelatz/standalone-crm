<?php

namespace App\Modules\Crm\Deals\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class MoveDealStageData extends Data
{
    /**
     * @param  array<string, mixed>  $core_field_updates
     * @param  array<string, mixed>  $custom_field_updates
     */
    public function __construct(
        public string $target_stage_id,
        public int $expected_version,
        public ?string $before_deal_id = null,
        public ?string $after_deal_id = null,
        public array $core_field_updates = [],
        public array $custom_field_updates = [],
        public bool $override_gates = false,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'target_stage_id' => ['required', 'uuid'],
            'expected_version' => ['required', 'integer', 'min:1'],
            'before_deal_id' => ['nullable', 'uuid'],
            'after_deal_id' => ['nullable', 'uuid'],
            'core_field_updates' => ['sometimes', 'array'],
            'core_field_updates.amount_cents' => ['sometimes', 'integer', 'min:0', 'max:999999999999999'],
            'core_field_updates.expected_close_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'core_field_updates.lost_reason' => ['sometimes', 'nullable', 'string', 'max:255'],
            'core_field_updates.primary_contact_id' => ['sometimes', 'nullable', 'uuid'],
            'custom_field_updates' => ['sometimes', 'array'],
            'override_gates' => ['sometimes', 'boolean'],
        ];
    }
}
