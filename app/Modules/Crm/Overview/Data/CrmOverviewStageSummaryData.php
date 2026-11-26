<?php

namespace App\Modules\Crm\Overview\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class CrmOverviewStageSummaryData extends Data
{
    public function __construct(
        public string $stage_id,
        public string $stage_name,
        public string $stage_type,
        public int $win_probability,
        public int $deals_count,
        public int $total_amount_cents,
        public int $weighted_amount_cents,
    ) {}
}
