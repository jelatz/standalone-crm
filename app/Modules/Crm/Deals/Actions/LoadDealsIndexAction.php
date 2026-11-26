<?php

namespace App\Modules\Crm\Deals\Actions;

use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Deals\Data\DealFilterData;
use App\Modules\Crm\Deals\Models\Deal;
use App\Modules\Crm\Shared\Actions\LoadEntityContextAction;
use App\Modules\Projects\Handoffs\Models\DealHandoff;
use App\Modules\Projects\Handoffs\Support\DealHandoffQuerySupport;
use Illuminate\Support\Facades\Gate;

class LoadDealsIndexAction
{
    public function __construct(
        protected ListDealsAction $listDeals,
        protected LoadEntityContextAction $entityContext,
        protected LoadDealContextAction $dealContext,
        protected DealHandoffQuerySupport $handoffQuerySupport,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(User $viewer, DealFilterData $filter): array
    {
        $view = $filter->view === 'table' ? 'table' : 'kanban';
        $canCreate = Gate::forUser($viewer)->allows('create', Deal::class);
        $canSubmitHandoff = Gate::forUser($viewer)->allows('submit', DealHandoff::class);

        $board = null;
        $tableDeals = null;
        $effectivePipelineId = $filter->pipeline_id !== null && trim($filter->pipeline_id) !== ''
            ? trim($filter->pipeline_id)
            : null;

        if ($view === 'kanban') {
            $pipeline = $this->listDeals->resolveBoardPipeline($filter);
            $effectivePipelineId = (string) $pipeline->id;
            $board = $this->listDeals->executeBoard($viewer, $filter, $pipeline);
        } else {
            $tableDeals = $this->listDeals->executeTable($viewer, $filter)->withQueryString();
        }

        return [
            'view' => $view,
            'board' => $board,
            'tableDeals' => $tableDeals,
            'filters' => $filter->toArray(),
            'pipelines' => $this->dealContext->activePipelines(),
            'filterDefinitions' => $this->dealContext->dealCustomFieldDefinitions($effectivePipelineId, filterableOnly: true),
            'customFieldDefinitions' => $this->dealContext->dealCustomFieldDefinitions($effectivePipelineId),
            'assignableUsers' => $this->entityContext->assignableUsers($viewer),
            'assignableAms' => $canSubmitHandoff ? $this->handoffQuerySupport->assignableAmsForViewer($viewer) : [],
            'companies' => $canCreate ? $this->entityContext->visibleCompanySummaries($viewer) : [],
            'contacts' => $canCreate ? $this->dealContext->visibleContactSummaries($viewer) : [],
            'can' => [
                'create' => $canCreate,
                'submitHandoff' => $canSubmitHandoff,
            ],
        ];
    }
}
