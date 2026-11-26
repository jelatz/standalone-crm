<?php

namespace App\Modules\Crm\Leads\Data;

use App\Modules\Crm\Leads\Actions\ImportLeadsAction;
use App\Modules\Crm\Leads\Enums\LeadSourceEnum;
use Closure;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ImportLeadRowPayloadData extends Data
{
    /**
     * @param  array<string, mixed>  $custom_fields
     */
    public function __construct(
        public string $first_name,
        public string $last_name,
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $job_title = null,
        public ?string $company_name = null,
        public ?string $website = null,
        public string $source = 'outbound',
        public ?int $estimated_value_cents = null,
        public string $currency = 'USD',
        public array $custom_fields = [],
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'min:1', 'max:100'],
            'last_name' => ['required', 'string', 'min:1', 'max:100'],
            'email' => ['nullable', 'email:filter', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'company_name' => ['nullable', 'string', 'max:200'],
            'website' => [
                'nullable',
                'string',
                'max:255',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value !== null && ! ImportLeadsAction::isSafeWebsiteValue((string) $value)) {
                        $fail('Website must be a valid domain or http(s) URL.');
                    }
                },
            ],
            'source' => ['required', Rule::enum(LeadSourceEnum::class)],
            'estimated_value_cents' => ['nullable', 'integer', 'min:0', 'max:999999999999999'],
            'currency' => ['required', 'string', 'size:3'],
            'custom_fields' => ['sometimes', 'array'],
        ];
    }
}
