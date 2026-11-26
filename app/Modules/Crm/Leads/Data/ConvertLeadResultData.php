<?php

namespace App\Modules\Crm\Leads\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ConvertLeadResultData extends Data
{
    public function __construct(
        public LeadDetailData $lead,
        public string $company_id,
        public string $contact_id,
        public ?string $deal_id = null,
    ) {}
}
