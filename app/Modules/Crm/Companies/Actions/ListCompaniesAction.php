<?php

namespace App\Modules\Crm\Companies\Actions;

use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Companies\Data\CompanyFilterData;
use App\Modules\Crm\Companies\Data\CompanyListItemData;
use App\Modules\Crm\Companies\Models\Company;
use App\Modules\Crm\CustomFields\Actions\ApplyCustomFieldFiltersAction;
use App\Modules\Crm\CustomFields\Enums\CustomFieldEntityTypeEnum;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ListCompaniesAction
{
    public function __construct(
        protected ApplyCustomFieldFiltersAction $applyCustomFieldFilters,
    ) {}

    /**
     * @return LengthAwarePaginator<int, CompanyListItemData>
     */
    public function execute(User $viewer, CompanyFilterData $filter): LengthAwarePaginator
    {
        $perPage = max(1, min((int) ($filter->per_page ?? 25), 100));
        $page = max(1, (int) ($filter->page ?? 1));

        $query = $this->buildQuery($viewer, $filter)
            ->with(['owner:id,name', 'accountManager:id,name'])
            ->withCount([
                'contacts' => static fn (Builder $sub) => $sub->visibleTo($viewer),
                'deals' => static fn (Builder $sub) => $sub->visibleTo($viewer),
            ]);

        /** @var LengthAwarePaginator<int, Company> $paginator */
        $paginator = $query->paginate(perPage: $perPage, page: $page);

        return $paginator->through(
            static fn (Company $company): CompanyListItemData => CompanyListItemData::fromModel($company),
        );
    }

    /**
     * Build the silo-scoped, filtered, and sorted Company query.
     *
     * @return Builder<Company>
     */
    public function buildQuery(User $viewer, CompanyFilterData $filter): Builder
    {
        $query = Company::query()->visibleTo($viewer);

        if ($filter->search !== null && trim($filter->search) !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($filter->search));
            $pattern = "%{$escaped}%";
            $likeOperator = DB::getDriverName() === 'pgsql' ? 'ilike' : 'like';

            $query->where(function (Builder $sub) use ($pattern, $likeOperator): void {
                $sub->where('companies.name', $likeOperator, $pattern)
                    ->orWhere('companies.domain', $likeOperator, $pattern);
            });
        }

        if ($filter->lifecycle_stage !== null) {
            $query->where('companies.lifecycle_stage', $filter->lifecycle_stage->value);
        }

        if ($filter->owner_id !== null && trim($filter->owner_id) !== '') {
            $query->where('companies.owner_id', $filter->owner_id);
        }

        $this->applyCustomFieldFilters->execute(
            query: $query,
            entityType: CustomFieldEntityTypeEnum::Company,
            filters: $filter->custom_fields,
            qualifiedColumn: 'companies.custom_fields',
        );

        $sortBy = in_array($filter->sort_by, CompanyFilterData::ALLOWED_SORT_FIELDS, true)
            ? $filter->sort_by
            : 'updated_at';

        $sortDir = $filter->sort_dir === 'asc' ? 'asc' : 'desc';

        return $query
            ->orderBy("companies.{$sortBy}", $sortDir)
            ->orderBy('companies.id', $sortDir);
    }
}
