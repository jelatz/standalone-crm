<?php

namespace App\Modules\Projects\Handoffs\Actions;

use App\Modules\Core\Rbac\Enums\PermissionEnum;
use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Companies\Models\Company;
use App\Modules\Projects\Handoffs\Data\DealHandoffSummaryData;
use App\Modules\Projects\Handoffs\Enums\DealHandoffStatusEnum;
use App\Modules\Projects\Handoffs\Models\DealHandoff;
use App\Modules\Projects\Handoffs\Support\DealHandoffQuerySupport;
use App\Modules\Projects\Projects\Models\Project;
use App\Support\Silo\VisibleScopeResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class ListHandoffQueueAction
{
    public function __construct(
        protected VisibleScopeResolver $scopeResolver,
        protected DealHandoffQuerySupport $querySupport,
    ) {}

    /**
     * Build the authorized, silo-scoped Inertia props for HandoffIntakePage.
     *
     * @return array<string, mixed>
     */
    public function execute(User $viewer, ?string $statusFilter = null): array
    {
        Gate::forUser($viewer)->authorize('viewAny', DealHandoff::class);

        $baseQuery = $this->scopedQuery($viewer);

        $rawCounts = (clone $baseQuery)
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $statusCounts = [];
        foreach (DealHandoffStatusEnum::cases() as $case) {
            $statusCounts[$case->value] = (int) ($rawCounts[$case->value] ?? 0);
        }

        $parsedStatus = $statusFilter !== null ? DealHandoffStatusEnum::tryFrom($statusFilter) : null;

        $handoffModels = (clone $baseQuery)
            ->with(DealHandoffQuerySupport::eagerLoadRelations())
            ->when(
                $parsedStatus !== null,
                static fn (Builder $q) => $q->where('status', $parsedStatus->value),
            )
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        $companyIds = $handoffModels
            ->pluck('company_id')
            ->filter()
            ->map(static fn (mixed $id): string => (string) $id)
            ->unique()
            ->values()
            ->all();

        $projectIds = $handoffModels
            ->map(static fn (DealHandoff $handoff): ?string => $handoff->project !== null ? (string) $handoff->project->id : null)
            ->filter()
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

        /** @var array<string, int|string> $visibleProjectIds */
        $visibleProjectIds = ($projectIds !== [] && Gate::forUser($viewer)->allows('viewAny', Project::class))
            ? Project::query()
                ->visibleTo($viewer)
                ->whereIn('id', $projectIds)
                ->pluck('id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->flip()
                ->all()
            : [];

        $handoffs = $handoffModels
            ->map(static function (DealHandoff $handoff) use ($visibleCompanyIds, $visibleProjectIds): DealHandoffSummaryData {
                $companyId = (string) $handoff->company_id;
                $projectId = $handoff->project !== null ? (string) $handoff->project->id : null;

                return DealHandoffSummaryData::fromModel(
                    handoff: $handoff,
                    canViewCompany: isset($visibleCompanyIds[$companyId]),
                    canViewProject: $projectId !== null && isset($visibleProjectIds[$projectId]),
                );
            })
            ->values()
            ->all();

        return [
            'handoffs' => $handoffs,
            'statusCounts' => $statusCounts,
            'activeStatus' => $parsedStatus?->value,
            'templates' => $this->querySupport->templatesForViewer($viewer),
            'assignablePms' => $this->querySupport->assignablePmsForViewer($viewer),
            'assignableAms' => $this->querySupport->assignableAmsForViewer($viewer),
            'can' => [
                'review' => $viewer->checkPermissionTo(PermissionEnum::HandoffsReview->value),
                'approve' => $viewer->checkPermissionTo(PermissionEnum::HandoffsApprove->value),
                'submit' => $viewer->checkPermissionTo(PermissionEnum::HandoffsSubmit->value),
            ],
        ];
    }

    /**
     * Mirror DealHandoffPolicy::view visibility rules in SQL.
     *
     * @return Builder<DealHandoff>
     */
    protected function scopedQuery(User $viewer): Builder
    {
        $query = DealHandoff::query();
        $scope = $this->scopeResolver->for($viewer);

        if ($scope->isUnrestrictedAdmin || $viewer->checkPermissionTo(PermissionEnum::HandoffsReview->value)) {
            return $query;
        }

        $viewerId = (string) $viewer->id;
        $canViewDeals = $viewer->checkPermissionTo(PermissionEnum::DealsView->value);

        return $query->where(static function (Builder $sub) use ($viewer, $viewerId, $canViewDeals): void {
            $sub->where('submitted_by_id', $viewerId)
                ->orWhere('assigned_pm_id', $viewerId)
                ->orWhere('assigned_am_id', $viewerId)
                ->when(
                    $canViewDeals,
                    static function (Builder $dealSubQuery) use ($viewer): void {
                        $dealSubQuery->orWhereHas('deal', static function (Builder $dealQuery) use ($viewer): void {
                            $dealQuery->visibleTo($viewer);
                        });
                    },
                );
        });
    }
}
