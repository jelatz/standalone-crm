<?php

namespace App\Modules\Crm\Companies\Data;

use App\Modules\Crm\Companies\Enums\CompanyLifecycleStageEnum;
use App\Modules\Crm\Companies\Models\Company;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;
use Spatie\LaravelData\Support\Validation\ValidationContext;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class UpsertCompanyData extends Data
{
    /**
     * @param  array<string, mixed>|Optional|null  $billing_address
     * @param  array<string, mixed>|Optional  $custom_fields
     */
    public function __construct(
        public string|Optional $name = new Optional,
        public string|Optional|null $domain = new Optional,
        public CompanyLifecycleStageEnum|Optional $lifecycle_stage = new Optional,
        public string|Optional|null $industry = new Optional,
        public string|Optional|null $employee_range = new Optional,
        public int|Optional|null $annual_revenue_cents = new Optional,
        public array|Optional|null $billing_address = new Optional,
        public string|Optional|null $account_manager_id = new Optional,
        public array|Optional $custom_fields = new Optional,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(ValidationContext $context): array
    {
        $isCreate = ! (request()->route('company') instanceof Company);

        return [
            'name' => [$isCreate ? 'required' : 'sometimes', 'string', 'min:1', 'max:200'],
            'domain' => ['sometimes', 'nullable', 'string', 'max:255'],
            'lifecycle_stage' => ['sometimes', Rule::enum(CompanyLifecycleStageEnum::class)],
            'industry' => ['sometimes', 'nullable', 'string', 'max:100'],
            'employee_range' => ['sometimes', 'nullable', 'string', 'max:32'],
            'annual_revenue_cents' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:999999999999999'],
            'billing_address' => ['sometimes', 'nullable', 'array'],
            'account_manager_id' => ['sometimes', 'nullable', 'uuid'],
            'custom_fields' => ['sometimes', 'array'],
        ];
    }
}
