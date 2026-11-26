<?php

namespace App\Modules\Crm\Deals\Actions;

use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Companies\Models\Company;
use App\Modules\Crm\Contacts\Models\Contact;
use App\Modules\Crm\CustomFields\Actions\ApplyCustomFieldFiltersAction;
use App\Modules\Crm\CustomFields\Enums\CustomFieldEntityTypeEnum;
use App\Modules\Crm\Deals\Data\DealCardData;
use App\Modules\Crm\Deals\Data\DealFilterData;
use App\Modules\Crm\Deals\Data\DealKanbanBoardData;
use App\Modules\Crm\Deals\Data\DealKanbanStageColumnData;
use App\Modules\Crm\Deals\Models\Deal;
use App\Modules\Crm\Pipelines\Data\PipelineData;
use App\Modules\Crm\Pipelines\Data\PipelineStageData;
use App\Modules\Crm\Pipelines\Models\Pipeline;
use App\Modules\Crm\Pipelines\Models\PipelineStage;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ListDealsAction
{
    public function __construct(
        protected ApplyCustomFieldFiltersAction $applyCustomFieldFilters,
    ) {}

    /**
     * @return DealKanbanBoardData|LengthAwarePaginator<int, DealCardData>
     */
    public function execute(User $viewer, DealFilterData $filter): DealKanbanBoardData|LengthAwarePaginator
    {
        $view = $filter->view === 'table' ? 'table' : 'kanban';

        return $view === 'table'
            ? $this->executeTable($viewer, $filter)
            : $this->executeBoard($viewer, $filter);
    }

    public function resolveBoardPipeline(DealFilterData $filter): Pipeline
    {
        $query = Pipeline::query()->with([
            'stages' => static fn ($q) => $q->with('requiredCustomFields')->orderBy('sort_order'),
        ]);

        if ($filter->pipeline_id !== null && trim($filter->pipeline_id) !== '') {
            return $query->findOrFail(trim($filter->pipeline_id));
        }

        $activeDefault = (clone $query)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->first();

        if ($activeDefault !== null) {
            return $activeDefault;
        }

        return $query
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->firstOrFail();
    }

    public function executeBoard(User $viewer, DealFilterData $filter, ?Pipeline $resolvedPipeline = null): DealKanbanBoardData
    {
        $pipeline = $resolvedPipeline ?? $this->resolveBoardPipeline($filter);
        $pipeline->loadMissing([
            'stages' => static fn ($q) => $q->with('requiredCustomFields')->orderBy('sort_order'),
        ]);

        $boardFilter = new DealFilterData(
            view: 'kanban',
            pipeline_id: (string) $pipeline->id,
            stage_id: $filter->stage_id,
            owner_id: $filter->owner_id,
            company_id: $filter->company_id,
            search: $filter->search,
            custom_fields: $filter->custom_fields,
            sort_by: 'board_position',
            sort_dir: 'asc',
            page: 1,
            per_page: 25,
        );

        /** @var EloquentCollection<int, Deal> $deals */
        $deals = $this->buildQuery($viewer, $boardFilter, (string) $pipeline->id)
            ->with([
                'company:id,name',
                'primaryContact:id,first_name,last_name,full_name',
                'stage:id,name,stage_type,rotting_days',
                'owner:id,name',
                'accountManager:id,name',
            ])
            ->orderBy('deals.board_position')
            ->orderBy('deals.id')
            ->get();

        $cardsByStage = $this->mapVisibleDealCards($viewer, $deals)
            ->groupBy(static fn (DealCardData $card): string => $card->stage_id);

        $columns = [];
        $totalDealsCount = 0;
        $totalAmountCents = 0;

        foreach ($pipeline->stages as $stage) {
            /** @var PipelineStage $stage */
            /** @var list<DealCardData> $stageCards */
            $stageCards = $cardsByStage->get((string) $stage->id)?->values()->all() ?? [];
            $stageCount = count($stageCards);
            $stageAmount = array_sum(array_map(static fn (DealCardData $card): int => $card->amount_cents, $stageCards));

            $totalDealsCount += $stageCount;
            $totalAmountCents += $stageAmount;

            $columns[] = new DealKanbanStageColumnData(
                stage: PipelineStageData::fromModel($stage),
                deals_count: $stageCount,
                total_amount_cents: $stageAmount,
                deals: $stageCards,
            );
        }

        return new DealKanbanBoardData(
            pipeline: PipelineData::fromModel($pipeline),
            columns: $columns,
            total_deals_count: $totalDealsCount,
            total_amount_cents: $totalAmountCents,
        );
    }

    /**
     * @return LengthAwarePaginator<int, DealCardData>
     */
    public function executeTable(User $viewer, DealFilterData $filter): LengthAwarePaginator
    {
        $perPage = max(1, min((int) ($filter->per_page ?? 25), 100));
        $page = max(1, (int) ($filter->page ?? 1));

        $pipelineId = $filter->pipeline_id !== null && trim($filter->pipeline_id) !== ''
            ? trim($filter->pipeline_id)
            : null;

        $sortBy = in_array($filter->sort_by, DealFilterData::ALLOWED_SORT_FIELDS, true)
            ? $filter->sort_by
            : 'updated_at';

        $sortDir = $filter->sort_dir === 'asc' ? 'asc' : 'desc';

        $query = $this->buildQuery($viewer, $filter, $pipelineId)
            ->with([
                'company:id,name',
                'primaryContact:id,first_name,last_name,full_name',
                'stage:id,name,stage_type,rotting_days',
                'owner:id,name',
                'accountManager:id,name',
            ])
            ->orderBy("deals.{$sortBy}", $sortDir)
            ->orderBy('deals.id', $sortDir);

        /** @var LengthAwarePaginator<int, Deal> $paginator */
        $paginator = $query->paginate(perPage: $perPage, page: $page);

        $mappedById = $this->mapVisibleDealCards($viewer, new EloquentCollection($paginator->items()))
            ->keyBy(static fn (DealCardData $card): string => $card->id);

        return $paginator->through(
            static fn (Deal $deal): DealCardData => $mappedById->get((string) $deal->id)
                ?? DealCardData::fromModel($deal, false, false),
        );
    }

    /**
     * Build the silo-scoped and filtered Deal query, preventing company_id filter oracles.
     *
     * @return Builder<Deal>
     */
    public function buildQuery(User $viewer, DealFilterData $filter, ?string $pipelineId = null): Builder
    {
        $query = Deal::query()->visibleTo($viewer);

        $effectivePipelineId = $pipelineId ?? ($filter->pipeline_id !== null && trim($filter->pipeline_id) !== '' ? trim($filter->pipeline_id) : null);

        $this->applyCustomFieldFilters->execute(
            query: $query,
            entityType: CustomFieldEntityTypeEnum::Deal,
            filters: $filter->custom_fields,
            qualifiedColumn: 'deals.custom_fields',
            pipelineId: $effectivePipelineId,
        );

        if ($effectivePipelineId !== null) {
            $query->where('deals.pipeline_id', $effectivePipelineId);
        }

        if ($filter->stage_id !== null && trim($filter->stage_id) !== '') {
            $query->where('deals.stage_id', trim($filter->stage_id));
        }

        if ($filter->owner_id !== null && trim($filter->owner_id) !== '') {
            $query->where('deals.owner_id', trim($filter->owner_id));
        }

        if ($filter->company_id !== null && trim($filter->company_id) !== '') {
            $companyId = trim($filter->company_id);
            $canFilterCompany = Gate::forUser($viewer)->allows('viewAny', Company::class)
                && Company::query()->visibleTo($viewer)->whereKey($companyId)->exists();

            if (! $canFilterCompany) {
                return $query->whereRaw('0 = 1');
            }

            $query->where('deals.company_id', $companyId);
        }

        if ($filter->search !== null && trim($filter->search) !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($filter->search));
            $pattern = "%{$escaped}%";
            $likeOperator = DB::getDriverName() === 'pgsql' ? 'ilike' : 'like';

            $query->where('deals.title', $likeOperator, $pattern);
        }

        return $query;
    }

    /**
     * @param  EloquentCollection<int, Deal>  $deals
     * @return Collection<int, DealCardData>
     */
    protected function mapVisibleDealCards(User $viewer, EloquentCollection $deals): Collection
    {
        $companyIds = $deals->pluck('company_id')
            ->filter()
            ->map(static fn (mixed $id): string => (string) $id)
            ->unique()
            ->values()
            ->all();

        $contactIds = $deals->pluck('primary_contact_id')
            ->filter()
            ->map(static fn (mixed $id): string => (string) $id)
            ->unique()
            ->values()
            ->all();

        /** @var array<string, int|string> $visibleCompanyIds */
        $visibleCompanyIds = ($companyIds !== [] && Gate::forUser($viewer)->allows('viewAny', Company::class))
            ? Company::query()
                ->visibleTo($viewer)
                ->whereIn('id', $companyIds)
                ->pluck('id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->flip()
                ->all()
            : [];

        /** @var array<string, int|string> $visibleContactIds */
        $visibleContactIds = ($contactIds !== [] && Gate::forUser($viewer)->allows('viewAny', Contact::class))
            ? Contact::query()
                ->visibleTo($viewer)
                ->whereIn('id', $contactIds)
                ->pluck('id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->flip()
                ->all()
            : [];

        return $deals->map(
            static fn (Deal $deal): DealCardData => DealCardData::fromModel(
                deal: $deal,
                canViewCompany: isset($visibleCompanyIds[(string) $deal->company_id]),
                canViewPrimaryContact: $deal->primary_contact_id !== null
                    && isset($visibleContactIds[(string) $deal->primary_contact_id]),
            ),
        );
    }
}
