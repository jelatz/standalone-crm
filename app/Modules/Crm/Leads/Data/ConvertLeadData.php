<?php

namespace App\Modules\Crm\Leads\Data;

use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ConvertLeadData extends Data
{
    public function __construct(
        public string $company_mode = 'create',
        public ?string $existing_company_id = null,
        public ?string $company_name = null,
        public ?string $company_domain = null,
        public bool $is_primary_contact = true,
        public bool $create_deal = true,
        public ?string $deal_title = null,
        public ?string $pipeline_id = null,
        public ?string $stage_id = null,
        public int $amount_cents = 0,
        public string $currency = 'USD',
        public ?string $expected_close_date = null,
        public ?int $win_probability = null,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'company_mode' => ['required', 'string', Rule::in(['create', 'existing'])],
            'existing_company_id' => ['nullable', 'required_if:company_mode,existing', 'uuid'],
            'company_name' => ['nullable', 'required_if:company_mode,create', 'string', 'min:1', 'max:200'],
            'company_domain' => ['nullable', 'string', 'max:255'],
            'is_primary_contact' => ['sometimes', 'boolean'],
            'create_deal' => ['sometimes', 'boolean'],
            'deal_title' => ['nullable', 'required_if:create_deal,true', 'string', 'min:1', 'max:200'],
            'pipeline_id' => ['nullable', 'uuid'],
            'stage_id' => ['nullable', 'uuid'],
            'amount_cents' => ['sometimes', 'integer', 'min:0', 'max:999999999999999'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'expected_close_date' => ['nullable', 'date_format:Y-m-d'],
            'win_probability' => ['nullable', 'integer', 'min:0', 'max:100'],
        ];
    }
}
