<?php

namespace App\Modules\Projects\Handoffs\Data;

use App\Modules\Crm\Companies\Enums\CompanyLifecycleStageEnum;
use App\Modules\Crm\Companies\Models\Company;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class HandoffCompanySummaryData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $domain,
        public CompanyLifecycleStageEnum $lifecycle_stage,
    ) {}

    public static function fromModel(Company $company): self
    {
        return new self(
            id: (string) $company->id,
            name: $company->name,
            domain: $company->domain,
            lifecycle_stage: $company->lifecycle_stage,
        );
    }
}
