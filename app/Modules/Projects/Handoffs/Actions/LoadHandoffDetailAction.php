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

    public function buildDetailData(DealHandoff $handoff): DealHandoffDetailData
    {
        $loaded = $this->querySupport->loadFreshHandoff($handoff);

        return DealHandoffDetailData::fromModel($loaded);
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

        return [
            'handoff' => DealHandoffDetailData::fromModel($loaded),
            'templates' => $this->querySupport->templatesForViewer($viewer),
            'assignablePms' => $this->querySupport->assignablePmsForViewer($viewer),
            'assignableAms' => $this->querySupport->assignableAmsForViewer($viewer),
            'can' => [
                'resubmit' => Gate::forUser($viewer)->allows('resubmit', $loaded),
                'requestChanges' => Gate::forUser($viewer)->allows('requestChanges', $loaded),
                'reject' => Gate::forUser($viewer)->allows('reject', $loaded),
                'approve' => Gate::forUser($viewer)->allows('approve', $loaded),
                'viewProject' => $loaded->project !== null
                    && Gate::forUser($viewer)->allows('view', $loaded->project),
            ],
        ];
    }
}
