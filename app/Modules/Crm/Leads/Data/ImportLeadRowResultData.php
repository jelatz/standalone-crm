<?php

namespace App\Modules\Crm\Leads\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ImportLeadRowResultData extends Data
{
    public function __construct(
        public int $row_index,
        public string $full_name,
        public ?string $company_name,
        public ?string $email,
        public string $source,
        public string $outcome,
        public ?string $reason = null,
        public ?string $lead_id = null,
        public ?ImportLeadRowPayloadData $payload = null,
    ) {}
}
