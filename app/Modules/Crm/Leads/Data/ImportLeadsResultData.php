<?php

namespace App\Modules\Crm\Leads\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ImportLeadsResultData extends Data
{
    /**
     * @param  list<ImportLeadRowResultData>  $rows
     */
    public function __construct(
        public bool $dry_run,
        public int $total_rows,
        public int $ready_count,
        public int $inserted_count,
        public int $duplicate_count,
        public int $invalid_count,
        public array $rows,
    ) {}
}
