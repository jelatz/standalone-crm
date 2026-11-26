<?php

namespace App\Modules\Crm\Leads\Data;

use App\Modules\Crm\Leads\Enums\LeadSourceEnum;
use App\Modules\Crm\Leads\Enums\LeadStatusEnum;
use App\Modules\Crm\Leads\Models\Lead;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;
use Spatie\LaravelData\Support\Validation\ValidationContext;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class UpsertLeadData extends Data
{
    /**
     * @param  array<string, mixed>|Optional  $custom_fields
     */
    public function __construct(
        public string|Optional $first_name = new Optional,
        public string|Optional $last_name = new Optional,
        public string|Optional|null $email = new Optional,
        public string|Optional|null $phone = new Optional,
        public string|Optional|null $job_title = new Optional,
        public string|Optional|null $company_name = new Optional,
        public string|Optional|null $website = new Optional,
        public LeadStatusEnum|Optional $status = new Optional,
        public LeadSourceEnum|Optional $source = new Optional,
        public int|Optional|null $estimated_value_cents = new Optional,
        public string|Optional $currency = new Optional,
        public string|Optional|null $disqualified_reason = new Optional,
        public array|Optional $custom_fields = new Optional,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(ValidationContext $context): array
    {
        $isCreate = ! (request()->route('lead') instanceof Lead);

        return [
            'first_name' => [$isCreate ? 'required' : 'sometimes', 'string', 'min:1', 'max:100'],
            'last_name' => [$isCreate ? 'required' : 'sometimes', 'string', 'min:1', 'max:100'],
            'email' => ['sometimes', 'nullable', 'email:filter', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'job_title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'company_name' => ['sometimes', 'nullable', 'string', 'max:200'],
            'website' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => [
                'sometimes',
                Rule::enum(LeadStatusEnum::class),
                Rule::notIn([LeadStatusEnum::Converted->value]),
            ],
            'source' => ['sometimes', Rule::enum(LeadSourceEnum::class)],
            'estimated_value_cents' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:999999999999999'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'disqualified_reason' => ['sometimes', 'nullable', 'string', 'max:255'],
            'custom_fields' => ['sometimes', 'array'],
        ];
    }
}
