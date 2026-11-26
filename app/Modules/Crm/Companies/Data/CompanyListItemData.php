<?php

namespace App\Modules\Crm\Companies\Data;

use App\Modules\Crm\Companies\Enums\CompanyLifecycleStageEnum;
use App\Modules\Crm\Companies\Models\Company;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class CompanyListItemData extends Data
{
    /**
     * @param  array<string, mixed>  $custom_fields
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $domain,
        public CompanyLifecycleStageEnum $lifecycle_stage,
        public ?string $industry,
        public ?string $employee_range,
        public ?int $annual_revenue_cents,
        public string $owner_id,
        public ?string $owner_name,
        public ?string $account_manager_id,
        public ?string $account_manager_name,
        public int $contacts_count,
        public int $deals_count,
        public array $custom_fields,
        public string $created_at,
        public string $updated_at,
    ) {}

    public static function fromModel(Company $company): self
    {
        $owner = $company->relationLoaded('owner') ? $company->owner : null;
        $accountManager = $company->relationLoaded('accountManager') ? $company->accountManager : null;

        return new self(
            id: (string) $company->id,
            name: $company->name,
            domain: $company->domain,
            lifecycle_stage: $company->lifecycle_stage,
            industry: $company->industry,
            employee_range: $company->employee_range,
            annual_revenue_cents: $company->annual_revenue_cents,
            owner_id: (string) $company->owner_id,
            owner_name: $owner?->name,
            account_manager_id: $company->account_manager_id !== null ? (string) $company->account_manager_id : null,
            account_manager_name: $accountManager?->name,
            contacts_count: (int) ($company->getAttribute('contacts_count') ?? 0),
            deals_count: (int) ($company->getAttribute('deals_count') ?? 0),
            custom_fields: $company->custom_fields ?? [],
            created_at: $company->created_at?->toIso8601String() ?? now()->toIso8601String(),
            updated_at: $company->updated_at?->toIso8601String() ?? now()->toIso8601String(),
        );
    }
}
