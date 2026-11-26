<?php

namespace App\Modules\Crm\Leads\Actions;

use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Companies\Models\Company;
use App\Modules\Crm\Contacts\Models\Contact;
use App\Modules\Crm\CustomFields\Actions\ApplyCustomFieldFiltersAction;
use App\Modules\Crm\CustomFields\Enums\CustomFieldEntityTypeEnum;
use App\Modules\Crm\Deals\Models\Deal;
use App\Modules\Crm\Leads\Data\LeadFilterData;
use App\Modules\Crm\Leads\Data\LeadListItemData;
use App\Modules\Crm\Leads\Enums\LeadStatusEnum;
use App\Modules\Crm\Leads\Models\Lead;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ListLeadsAction
{
    public function __construct(
        protected ApplyCustomFieldFiltersAction $applyCustomFieldFilters,
    ) {}

    /**
     * @return LengthAwarePaginator<int, LeadListItemData>
     */
    public function execute(User $viewer, LeadFilterData $filter): LengthAwarePaginator
    {
        $perPage = max(1, min((int) ($filter->per_page ?? 25), 100));
        $page = max(1, (int) ($filter->page ?? 1));

        /** @var LengthAwarePaginator<int, Lead> $paginator */
        $paginator = $this->buildQuery($viewer, $filter)
            ->with('owner:id,name')
            ->paginate(perPage: $perPage, page: $page);

        $items = $paginator->getCollection();

        $companyIds = $items->pluck('converted_company_id')->filter()->map(static fn (mixed $id): string => (string) $id)->unique()->values()->all();
        $contactIds = $items->pluck('converted_contact_id')->filter()->map(static fn (mixed $id): string => (string) $id)->unique()->values()->all();
        $dealIds = $items->pluck('converted_deal_id')->filter()->map(static fn (mixed $id): string => (string) $id)->unique()->values()->all();

        $visibleCompanyIds = ($companyIds !== [] && Gate::forUser($viewer)->allows('viewAny', Company::class))
            ? Company::query()->visibleTo($viewer)->whereIn('id', $companyIds)->pluck('id')->map(static fn (mixed $id): string => (string) $id)->flip()->all()
            : [];

        $visibleContactIds = ($contactIds !== [] && Gate::forUser($viewer)->allows('viewAny', Contact::class))
            ? Contact::query()->visibleTo($viewer)->whereIn('id', $contactIds)->pluck('id')->map(static fn (mixed $id): string => (string) $id)->flip()->all()
            : [];

        $visibleDealIds = ($dealIds !== [] && Gate::forUser($viewer)->allows('viewAny', Deal::class))
            ? Deal::query()->visibleTo($viewer)->whereIn('id', $dealIds)->pluck('id')->map(static fn (mixed $id): string => (string) $id)->flip()->all()
            : [];

        return $paginator->through(
            static fn (Lead $lead): LeadListItemData => LeadListItemData::fromModel(
                lead: $lead,
                canViewConvertedCompany: $lead->converted_company_id !== null && isset($visibleCompanyIds[(string) $lead->converted_company_id]),
                canViewConvertedContact: $lead->converted_contact_id !== null && isset($visibleContactIds[(string) $lead->converted_contact_id]),
                canViewConvertedDeal: $lead->converted_deal_id !== null && isset($visibleDealIds[(string) $lead->converted_deal_id]),
            ),
        );
    }

    /**
     * Compute silo-scoped status counts matching the non-status filter criteria.
     *
     * @return array<string, int>
     */
    public function statusCounts(User $viewer, LeadFilterData $filter): array
    {
        $withoutStatus = new LeadFilterData(
            search: $filter->search,
            status: null,
            source: $filter->source,
            owner_id: $filter->owner_id,
            custom_fields: $filter->custom_fields,
        );

        $rows = $this->buildQuery($viewer, $withoutStatus, applySort: false)
            ->selectRaw('leads.status, count(*) as aggregate_count')
            ->groupBy('leads.status')
            ->pluck('aggregate_count', 'status')
            ->all();

        $counts = ['all' => 0];
        foreach (LeadStatusEnum::cases() as $statusCase) {
            $count = (int) ($rows[$statusCase->value] ?? 0);
            $counts[$statusCase->value] = $count;
            $counts['all'] += $count;
        }

        return $counts;
    }

    /**
     * @return Builder<Lead>
     */
    public function buildQuery(User $viewer, LeadFilterData $filter, bool $applySort = true): Builder
    {
        $query = Lead::query()->visibleTo($viewer);

        if ($filter->search !== null && trim($filter->search) !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($filter->search));
            $pattern = "%{$escaped}%";
            $likeOperator = DB::getDriverName() === 'pgsql' ? 'ilike' : 'like';

            $query->where(function (Builder $sub) use ($pattern, $likeOperator): void {
                $sub->where('leads.first_name', $likeOperator, $pattern)
                    ->orWhere('leads.last_name', $likeOperator, $pattern)
                    ->orWhere('leads.email', $likeOperator, $pattern)
                    ->orWhere('leads.company_name', $likeOperator, $pattern);
            });
        }

        if ($filter->status !== null) {
            $query->where('leads.status', $filter->status->value);
        }

        if ($filter->source !== null) {
            $query->where('leads.source', $filter->source->value);
        }

        if ($filter->owner_id !== null && trim($filter->owner_id) !== '') {
            $query->where('leads.owner_id', trim($filter->owner_id));
        }

        $this->applyCustomFieldFilters->execute(
            query: $query,
            entityType: CustomFieldEntityTypeEnum::Lead,
            filters: $filter->custom_fields,
            qualifiedColumn: 'leads.custom_fields',
        );

        if (! $applySort) {
            return $query;
        }

        $sortBy = in_array($filter->sort_by, LeadFilterData::ALLOWED_SORT_FIELDS, true)
            ? $filter->sort_by
            : 'updated_at';

        $sortDir = $filter->sort_dir === 'asc' ? 'asc' : 'desc';

        return $query
            ->orderBy("leads.{$sortBy}", $sortDir)
            ->orderBy('leads.id', $sortDir);
    }
}
