<?php

namespace App\Modules\Crm\Deals\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class CreateDealData extends Data
{
    /**
     * @param  array<string, mixed>  $custom_fields
     * @param  list<DealStakeholderInputData>|null  $stakeholders
     */
    public function __construct(
        public string $company_id,
        public string $title,
        public ?string $primary_contact_id = null,
        public ?string $pipeline_id = null,
        public ?string $stage_id = null,
        public ?string $account_manager_id = null,
        public int $amount_cents = 0,
        public string $currency = 'USD',
        public ?string $expected_close_date = null,
        public ?int $win_probability = null,
        public ?string $lost_reason = null,
        public array $custom_fields = [],
        #[DataCollectionOf(DealStakeholderInputData::class)]
        public ?array $stakeholders = null,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'company_id' => ['required', 'uuid'],
            'primary_contact_id' => ['nullable', 'uuid'],
            'pipeline_id' => ['nullable', 'uuid'],
            'stage_id' => ['nullable', 'uuid'],
            'account_manager_id' => ['nullable', 'uuid'],
            'title' => ['required', 'string', 'min:1', 'max:200'],
            'amount_cents' => ['sometimes', 'integer', 'min:0', 'max:999999999999999'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'expected_close_date' => ['nullable', 'date_format:Y-m-d'],
            'win_probability' => ['nullable', 'integer', 'min:0', 'max:100'],
            'lost_reason' => ['nullable', 'string', 'max:255'],
            'custom_fields' => ['sometimes', 'array'],
            'stakeholders' => ['sometimes', 'nullable', 'array'],
            'stakeholders.*.contact_id' => ['required', 'uuid', 'distinct'],
        ];
    }
}
