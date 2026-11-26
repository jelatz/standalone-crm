<?php

namespace App\Modules\Crm\Overview\Data;

use App\Modules\Crm\Deals\Data\DealCardData;
use App\Modules\Crm\Leads\Data\LeadListItemData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class CrmOverviewData extends Data
{
    /**
     * @param  list<CrmOverviewStageSummaryData>  $stage_breakdown
     * @param  list<LeadListItemData>  $recent_leads
     * @param  list<DealCardData>  $closing_soon_deals
     */
    public function __construct(
        public int $open_leads_count,
        public int $qualified_leads_count,
        public int $open_pipeline_cents,
        public int $open_deals_count,
        public int $weighted_forecast_cents,
        public int $won_this_month_cents,
        public int $won_this_month_count,
        public int $companies_count,
        public int $contacts_count,
        public ?string $pipeline_name,
        public array $stage_breakdown,
        public array $recent_leads,
        public array $closing_soon_deals,
    ) {}
}
