<?php

namespace App\Modules\Projects\Handoffs\Actions;

use App\Modules\Core\Rbac\Enums\PermissionEnum;
use App\Modules\Core\Users\Models\User;
use App\Modules\Projects\Handoffs\Data\DealHandoffSummaryData;
use App\Modules\Projects\Handoffs\Enums\DealHandoffStatusEnum;
use App\Modules\Projects\Handoffs\Models\DealHandoff;
use App\Modules\Projects\Handoffs\Support\DealHandoffQuerySupport;
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

        $handoffs = (clone $baseQuery)
            ->with(DealHandoffQuerySupport::eagerLoadRelations())
            ->when(
                $parsedStatus !== null,
                static fn (Builder $q) => $q->where('status', $parsedStatus->value),
            )
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(static fn (DealHandoff $handoff): DealHandoffSummaryData => DealHandoffSummaryData::fromModel($handoff))
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

        return $query->where(static function (Builder $sub) use ($viewer, $viewerId): void {
            $sub->where('submitted_by_id', $viewerId)
                ->orWhere('assigned_pm_id', $viewerId)
                ->orWhere('assigned_am_id', $viewerId)
                ->orWhereHas('deal', static function (Builder $dealQuery) use ($viewer): void {
                    $dealQuery->visibleTo($viewer);
                });
        });
    }
}
