<?php

namespace App\Modules\Projects\Projects\Actions;

use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Companies\Models\Company;
use App\Modules\Crm\Deals\Models\Deal;
use App\Modules\Projects\Projects\Data\ProjectFilterData;
use App\Modules\Projects\Projects\Data\ProjectSummaryData;
use App\Modules\Projects\Projects\Models\Project;
use App\Modules\Projects\Tasks\Enums\TaskStatusEnum;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ListProjectsAction
{
    /**
     * @return LengthAwarePaginator<int, ProjectSummaryData>
     */
    public function execute(User $viewer, ProjectFilterData $filter): LengthAwarePaginator
    {
        Gate::forUser($viewer)->authorize('viewAny', Project::class);

        $perPage = max(1, min((int) ($filter->per_page ?? 25), 100));
        $page = max(1, (int) ($filter->page ?? 1));

        /** @var LengthAwarePaginator<int, Project> $paginator */
        $paginator = $this->buildQuery($viewer, $filter)
            ->with([
                'owner:id,name',
                'accountManager:id,name',
                'department:id,name',
                'company:id,name',
                'primaryDeal:id,title',
            ])
            ->withCount([
                'tasks',
                'tasks as completed_tasks_count' => static fn (Builder $q) => $q->where('status', TaskStatusEnum::Done->value),
            ])
            ->paginate(perPage: $perPage, page: $page);

        $projects = new EloquentCollection($paginator->items());

        $companyIds = $projects->pluck('company_id')
            ->filter()
            ->map(static fn (mixed $id): string => (string) $id)
            ->unique()
            ->values()
            ->all();

        $dealIds = $projects->pluck('primary_deal_id')
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

        /** @var array<string, int|string> $visibleDealIds */
        $visibleDealIds = ($dealIds !== [] && Gate::forUser($viewer)->allows('viewAny', Deal::class))
            ? Deal::query()
                ->visibleTo($viewer)
                ->whereIn('id', $dealIds)
                ->pluck('id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->flip()
                ->all()
            : [];

        return $paginator->through(
            static fn (Project $project): ProjectSummaryData => ProjectSummaryData::fromModel(
                project: $project,
                canViewCompany: $project->company_id !== null && isset($visibleCompanyIds[(string) $project->company_id]),
                canViewDeal: $project->primary_deal_id !== null && isset($visibleDealIds[(string) $project->primary_deal_id]),
            ),
        );
    }

    /**
     * Build the silo-scoped, filtered, and sorted Project query without company_id filter oracles.
     *
     * @return Builder<Project>
     */
    public function buildQuery(User $viewer, ProjectFilterData $filter): Builder
    {
        $query = Project::query()->visibleTo($viewer);

        if ($filter->status !== null) {
            $query->where('projects.status', $filter->status->value);
        }

        if ($filter->health !== null) {
            $query->where('projects.health', $filter->health->value);
        }

        if ($filter->owner_id !== null && trim($filter->owner_id) !== '') {
            $query->where('projects.owner_id', trim($filter->owner_id));
        }

        if ($filter->company_id !== null && trim($filter->company_id) !== '') {
            $companyId = trim($filter->company_id);
            $canFilterCompany = Gate::forUser($viewer)->allows('viewAny', Company::class)
                && Company::query()->visibleTo($viewer)->whereKey($companyId)->exists();

            if (! $canFilterCompany) {
                return $query->whereRaw('0 = 1');
            }

            $query->where('projects.company_id', $companyId);
        }

        if ($filter->search !== null && trim($filter->search) !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($filter->search));
            $pattern = "%{$escaped}%";
            $likeOperator = DB::getDriverName() === 'pgsql' ? 'ilike' : 'like';

            $query->where(function (Builder $sub) use ($pattern, $likeOperator): void {
                $sub->where('projects.name', $likeOperator, $pattern)
                    ->orWhere('projects.code', $likeOperator, $pattern);
            });
        }

        $sortBy = in_array($filter->sort_by, ProjectFilterData::ALLOWED_SORT_FIELDS, true)
            ? $filter->sort_by
            : 'updated_at';

        $sortDir = $filter->sort_dir === 'asc' ? 'asc' : 'desc';

        return $query
            ->orderBy("projects.{$sortBy}", $sortDir)
            ->orderBy('projects.id', $sortDir);
    }
}
