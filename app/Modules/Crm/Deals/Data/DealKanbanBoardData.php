<?php

namespace App\Modules\Crm\Deals\Data;

use App\Modules\Crm\Pipelines\Data\PipelineData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class DealKanbanBoardData extends Data
{
    /**
     * @param  list<DealKanbanStageColumnData>  $columns
     */
    public function __construct(
        public PipelineData $pipeline,
        public array $columns,
        public int $total_deals_count,
        public int $total_amount_cents,
    ) {}
}
