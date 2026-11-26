<?php

namespace App\Modules\Crm\Deals\Data;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class UpdateDealData extends Data
{
    /**
     * @param  array<string, mixed>|Optional  $custom_fields
     */
    public function __construct(
        public int $expected_version,
        public string|Optional $company_id = new Optional,
        public string|Optional|null $primary_contact_id = new Optional,
        public string|Optional $stage_id = new Optional,
        public string|Optional|null $account_manager_id = new Optional,
        public string|Optional $title = new Optional,
        public int|Optional $amount_cents = new Optional,
        public string|Optional $currency = new Optional,
        public string|Optional|null $expected_close_date = new Optional,
        public string|Optional|null $lost_reason = new Optional,
        public array|Optional $custom_fields = new Optional,
        public bool $override_gates = false,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'expected_version' => ['required', 'integer', 'min:1'],
            'company_id' => ['sometimes', 'uuid'],
            'primary_contact_id' => ['sometimes', 'nullable', 'uuid'],
            'stage_id' => ['sometimes', 'uuid'],
            'account_manager_id' => ['sometimes', 'nullable', 'uuid'],
            'title' => ['sometimes', 'string', 'min:1', 'max:200'],
            'amount_cents' => ['sometimes', 'integer', 'min:0', 'max:999999999999999'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'expected_close_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'lost_reason' => ['sometimes', 'nullable', 'string', 'max:255'],
            'custom_fields' => ['sometimes', 'array'],
            'override_gates' => ['sometimes', 'boolean'],
        ];
    }
}
