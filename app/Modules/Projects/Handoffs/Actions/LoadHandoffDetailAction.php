<?php

namespace App\Modules\Projects\Handoffs\Actions;

use App\Modules\Core\Users\Models\User;
use App\Modules\Projects\Handoffs\Data\DealHandoffDetailData;
use App\Modules\Projects\Handoffs\Models\DealHandoff;
use App\Modules\Projects\Handoffs\Support\DealHandoffQuerySupport;
use Illuminate\Support\Facades\Gate;

class LoadHandoffDetailAction
{
    public function __construct(
        protected DealHandoffQuerySupport $querySupport,
    ) {}

    public function buildDetailData(DealHandoff $handoff, ?User $viewer = null): DealHandoffDetailData
    {
        $loaded = $this->querySupport->loadFreshHandoff($handoff);
        [$canViewCompany, $canViewDeal, $canViewProject] = $this->resolveVisibility($loaded, $viewer);

        return DealHandoffDetailData::fromModel(
            handoff: $loaded,
            canViewCompany: $canViewCompany,
            canViewDeal: $canViewDeal,
            canViewProject: $canViewProject,
        );
    }

    /**
     * Build the authorized Inertia props for HandoffDetailPage.
     *
     * @return array<string, mixed>
     */
    public function execute(User $viewer, DealHandoff $handoff): array
    {
        Gate::forUser($viewer)->authorize('view', $handoff);

        $loaded = $this->querySupport->loadFreshHandoff($handoff);
        [$canViewCompany, $canViewDeal, $canViewProject] = $this->resolveVisibility($loaded, $viewer);

        return [
            'handoff' => DealHandoffDetailData::fromModel(
                handoff: $loaded,
                canViewCompany: $canViewCompany,
                canViewDeal: $canViewDeal,
                canViewProject: $canViewProject,
            ),
            'templates' => $this->querySupport->templatesForViewer($viewer),
            'assignablePms' => $this->querySupport->assignablePmsForViewer($viewer),
            'assignableAms' => $this->querySupport->assignableAmsForViewer($viewer),
            'can' => [
                'resubmit' => Gate::forUser($viewer)->allows('resubmit', $loaded),
                'requestChanges' => Gate::forUser($viewer)->allows('requestChanges', $loaded),
                'reject' => Gate::forUser($viewer)->allows('reject', $loaded),
                'approve' => Gate::forUser($viewer)->allows('approve', $loaded),
                'viewProject' => $canViewProject,
            ],
        ];
    }

    /**
     * Resolve per-relationship cross-silo visibility flags for the handoff viewer.
     *
     * @return array{0: bool, 1: bool, 2: bool}
     */
    protected function resolveVisibility(DealHandoff $handoff, ?User $viewer): array
    {
        $canViewCompany = $handoff->company !== null
            && ($viewer === null || Gate::forUser($viewer)->allows('view', $handoff->company));
        $canViewDeal = $handoff->deal !== null
            && ($viewer === null || Gate::forUser($viewer)->allows('view', $handoff->deal));
        $canViewProject = $handoff->project !== null
            && ($viewer === null || Gate::forUser($viewer)->allows('view', $handoff->project));

        return [$canViewCompany, $canViewDeal, $canViewProject];
    }
}
