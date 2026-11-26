<?php

namespace App\Modules\Crm\Leads\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ImportLeadsPreviewData extends Data
{
    /**
     * @param  list<string>  $headers
     * @param  array<string, string|null>  $detected_mapping
     * @param  list<string>  $mappable_fields
     * @param  list<ImportLeadRowResultData>  $rows
     */
    public function __construct(
        public array $headers,
        public array $detected_mapping,
        public array $mappable_fields,
        public int $total_rows,
        public int $ready_count,
        public int $duplicate_count,
        public int $invalid_count,
        public array $rows,
    ) {}
}
