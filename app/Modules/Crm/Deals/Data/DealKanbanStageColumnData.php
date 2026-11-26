<?php

namespace App\Modules\Crm\Deals\Data;

use App\Modules\Crm\Pipelines\Data\PipelineStageData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class DealKanbanStageColumnData extends Data
{
    /**
     * @param  list<DealCardData>  $deals
     */
    public function __construct(
        public PipelineStageData $stage,
        public int $deals_count,
        public int $total_amount_cents,
        public array $deals,
    ) {}
}
