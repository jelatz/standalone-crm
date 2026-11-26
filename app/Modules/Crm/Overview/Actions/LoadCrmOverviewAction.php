<?php

namespace App\Modules\Crm\Overview\Actions;

use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Companies\Models\Company;
use App\Modules\Crm\Contacts\Models\Contact;
use App\Modules\Crm\Deals\Data\DealCardData;
use App\Modules\Crm\Deals\Models\Deal;
use App\Modules\Crm\Leads\Data\LeadListItemData;
use App\Modules\Crm\Leads\Enums\LeadStatusEnum;
use App\Modules\Crm\Leads\Models\Lead;
use App\Modules\Crm\Overview\Data\CrmOverviewData;
use App\Modules\Crm\Overview\Data\CrmOverviewStageSummaryData;
use App\Modules\Crm\Pipelines\Enums\PipelineStageTypeEnum;
use App\Modules\Crm\Pipelines\Models\Pipeline;
use App\Modules\Crm\Pipelines\Models\PipelineStage;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Gate;

class LoadCrmOverviewAction
{
    /**
     * @return array<string, mixed>
     */
    public function execute(User $viewer): array
    {
        $canViewLeads = Gate::forUser($viewer)->allows('viewAny', Lead::class);
        $canCreateLead = Gate::forUser($viewer)->allows('create', Lead::class);
        $canViewCompanies = Gate::forUser($viewer)->allows('viewAny', Company::class);
        $canViewContacts = Gate::forUser($viewer)->allows('viewAny', Contact::class);
        $canViewDeals = Gate::forUser($viewer)->allows('viewAny', Deal::class);
        $canCreateDeal = Gate::forUser($viewer)->allows('create', Deal::class);

        $openLeadsCount = 0;
        $qualifiedLeadsCount = 0;
        $recentLeads = [];

        if ($canViewLeads) {
            $openLeadsBase = Lead::query()
                ->visibleTo($viewer)
                ->whereNotIn('status', [
                    LeadStatusEnum::Unqualified->value,
                    LeadStatusEnum::Converted->value,
                ]);

            $openLeadsCount = (clone $openLeadsBase)->count();
            $qualifiedLeadsCount = Lead::query()
                ->visibleTo($viewer)
                ->where('status', LeadStatusEnum::Qualified->value)
                ->count();

            $recentLeads = (clone $openLeadsBase)
                ->with('owner:id,name')
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->limit(6)
                ->get()
                ->map(static fn (Lead $lead): LeadListItemData => LeadListItemData::fromModel($lead, false, false, false))
                ->values()
                ->all();
        }

        $companiesCount = $canViewCompanies ? Company::query()->visibleTo($viewer)->count() : 0;
        $contactsCount = $canViewContacts ? Contact::query()->visibleTo($viewer)->count() : 0;

        $openPipelineCents = 0;
        $openDealsCount = 0;
        $weightedForecastCents = 0;
        $wonThisMonthCents = 0;
        $wonThisMonthCount = 0;
        $pipelineName = null;
        $stageBreakdown = [];
        $closingSoonDeals = [];

        if ($canViewDeals) {
            /** @var EloquentCollection<int, Deal> $openDeals */
            $openDeals = Deal::query()
                ->visibleTo($viewer)
                ->whereHas('stage', static fn ($q) => $q->where('stage_type', PipelineStageTypeEnum::Open->value))
                ->with('stage:id,name,stage_type,win_probability')
                ->get(['id', 'pipeline_id', 'stage_id', 'amount_cents', 'win_probability']);

            $openDealsCount = $openDeals->count();
            foreach ($openDeals as $openDeal) {
                $openPipelineCents += (int) $openDeal->amount_cents;
                $prob = $openDeal->win_probability !== null
                    ? (int) $openDeal->win_probability
                    : ($openDeal->stage?->win_probability ?? 0);
                $weightedForecastCents += (int) round(($openDeal->amount_cents * $prob) / 100);
            }

            $wonQuery = Deal::query()
                ->visibleTo($viewer)
                ->whereNotNull('won_at')
                ->where('won_at', '>=', now()->startOfMonth());

            $wonThisMonthCount = (clone $wonQuery)->count();
            $wonThisMonthCents = (int) ((clone $wonQuery)->sum('amount_cents') ?? 0);

            $defaultPipeline = Pipeline::query()
                ->with(['stages' => static fn ($q) => $q->orderBy('sort_order')])
                ->where('is_active', true)
                ->orderByDesc('is_default')
                ->orderBy('sort_order')
                ->first();

            if ($defaultPipeline !== null) {
                $pipelineName = $defaultPipeline->name;
                $pipelineDeals = Deal::query()
                    ->visibleTo($viewer)
                    ->where('pipeline_id', $defaultPipeline->id)
                    ->get(['id', 'stage_id', 'amount_cents', 'win_probability'])
                    ->groupBy(static fn (Deal $deal): string => (string) $deal->stage_id);

                foreach ($defaultPipeline->stages as $stage) {
                    /** @var PipelineStage $stage */
                    /** @var EloquentCollection<int, Deal> $stageDeals */
                    $stageDeals = $pipelineDeals->get((string) $stage->id) ?? new EloquentCollection;
                    $totalAmount = 0;
                    $weightedAmount = 0;

                    foreach ($stageDeals as $stageDeal) {
                        $totalAmount += (int) $stageDeal->amount_cents;
                        $prob = $stageDeal->win_probability !== null
                            ? (int) $stageDeal->win_probability
                            : $stage->win_probability;
                        $weightedAmount += (int) round(($stageDeal->amount_cents * $prob) / 100);
                    }

                    $stageBreakdown[] = new CrmOverviewStageSummaryData(
                        stage_id: (string) $stage->id,
                        stage_name: $stage->name,
                        stage_type: $stage->stage_type->value,
                        win_probability: $stage->win_probability,
                        deals_count: $stageDeals->count(),
                        total_amount_cents: $totalAmount,
                        weighted_amount_cents: $weightedAmount,
                    );
                }
            }

            /** @var EloquentCollection<int, Deal> $closingSoonModels */
            $closingSoonModels = Deal::query()
                ->visibleTo($viewer)
                ->whereHas('stage', static fn ($q) => $q->where('stage_type', PipelineStageTypeEnum::Open->value))
                ->with([
                    'company:id,name',
                    'primaryContact:id,first_name,last_name,full_name',
                    'stage:id,name,stage_type,win_probability,rotting_days',
                    'owner:id,name',
                    'accountManager:id,name',
                ])
                ->orderByRaw('CASE WHEN expected_close_date IS NULL THEN 1 ELSE 0 END')
                ->orderBy('expected_close_date')
                ->orderByDesc('amount_cents')
                ->limit(6)
                ->get();

            $closingCompanyIds = $closingSoonModels->pluck('company_id')->filter()->map(static fn (mixed $id): string => (string) $id)->unique()->values()->all();
            $closingContactIds = $closingSoonModels->pluck('primary_contact_id')->filter()->map(static fn (mixed $id): string => (string) $id)->unique()->values()->all();

            $visibleCompanyIds = ($closingCompanyIds !== [] && $canViewCompanies)
                ? Company::query()->visibleTo($viewer)->whereIn('id', $closingCompanyIds)->pluck('id')->map(static fn (mixed $id): string => (string) $id)->flip()->all()
                : [];

            $visibleContactIds = ($closingContactIds !== [] && $canViewContacts)
                ? Contact::query()->visibleTo($viewer)->whereIn('id', $closingContactIds)->pluck('id')->map(static fn (mixed $id): string => (string) $id)->flip()->all()
                : [];

            $closingSoonDeals = $closingSoonModels
                ->map(static fn (Deal $deal): DealCardData => DealCardData::fromModel(
                    deal: $deal,
                    canViewCompany: isset($visibleCompanyIds[(string) $deal->company_id]),
                    canViewPrimaryContact: $deal->primary_contact_id !== null && isset($visibleContactIds[(string) $deal->primary_contact_id]),
                ))
                ->values()
                ->all();
        }

        $overview = new CrmOverviewData(
            open_leads_count: $openLeadsCount,
            qualified_leads_count: $qualifiedLeadsCount,
            open_pipeline_cents: $openPipelineCents,
            open_deals_count: $openDealsCount,
            weighted_forecast_cents: $weightedForecastCents,
            won_this_month_cents: $wonThisMonthCents,
            won_this_month_count: $wonThisMonthCount,
            companies_count: $companiesCount,
            contacts_count: $contactsCount,
            pipeline_name: $pipelineName,
            stage_breakdown: $stageBreakdown,
            recent_leads: $recentLeads,
            closing_soon_deals: $closingSoonDeals,
        );

        return [
            'overview' => $overview,
            'can' => [
                'viewLeads' => $canViewLeads,
                'createLead' => $canCreateLead,
                'viewCompanies' => $canViewCompanies,
                'viewContacts' => $canViewContacts,
                'viewDeals' => $canViewDeals,
                'createDeal' => $canCreateDeal,
            ],
        ];
    }
}
