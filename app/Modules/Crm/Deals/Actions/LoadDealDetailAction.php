<?php

namespace App\Modules\Crm\Deals\Actions;

use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Contacts\Models\Contact;
use App\Modules\Crm\Deals\Data\DealDetailData;
use App\Modules\Crm\Deals\Data\DealStakeholderData;
use App\Modules\Crm\Deals\Enums\DealStakeholderRoleEnum;
use App\Modules\Crm\Deals\Models\Deal;
use App\Modules\Crm\Pipelines\Data\PipelineData;
use App\Modules\Crm\Shared\Actions\LoadEntityContextAction;
use App\Modules\Crm\Timeline\Actions\QueryEntityTimelineAction;
use App\Modules\Projects\Handoffs\Data\DealHandoffSummaryData;
use App\Modules\Projects\Handoffs\Enums\DealHandoffStatusEnum;
use App\Modules\Projects\Handoffs\Models\DealHandoff;
use App\Modules\Projects\Handoffs\Support\DealHandoffQuerySupport;
use Illuminate\Support\Facades\Gate;

class LoadDealDetailAction
{
    public function __construct(
        protected QueryEntityTimelineAction $queryTimeline,
        protected LoadEntityContextAction $entityContext,
        protected LoadDealContextAction $dealContext,
        protected DealHandoffQuerySupport $handoffQuerySupport,
    ) {}

    /**
     * Build the authorized, silo- and permission-scoped Inertia props for DealDetailPage.
     *
     * @return array<string, mixed>
     */
    public function execute(User $viewer, Deal $deal): array
    {
        Gate::forUser($viewer)->authorize('view', $deal);

        $deal->load([
            'company:id,name,domain,lifecycle_stage,owner_id,account_manager_id',
            'primaryContact:id,company_id,first_name,last_name,full_name,primary_email,job_title,owner_id',
            'pipeline.stages.requiredCustomFields',
            'stage:id,pipeline_id,name,stage_type,win_probability,rotting_days,required_core_fields,sort_order',
            'owner:id,name',
            'accountManager:id,name',
        ]);

        $canViewCompany = $deal->company !== null
            && Gate::forUser($viewer)->allows('view', $deal->company);

        $linkedCompany = $canViewCompany && $deal->company !== null
            ? [
                'id' => (string) $deal->company->id,
                'name' => $deal->company->name,
                'domain' => $deal->company->domain,
                'lifecycle_stage' => $deal->company->lifecycle_stage->value,
            ]
            : null;

        $canViewPrimaryContact = $deal->primaryContact !== null
            && Gate::forUser($viewer)->allows('view', $deal->primaryContact);

        $linkedPrimaryContact = $canViewPrimaryContact && $deal->primaryContact !== null
            ? [
                'id' => (string) $deal->primaryContact->id,
                'full_name' => $deal->primaryContact->full_name
                    ?? trim($deal->primaryContact->first_name.' '.$deal->primaryContact->last_name),
                'primary_email' => $deal->primaryContact->primary_email,
                'job_title' => $deal->primaryContact->job_title,
            ]
            : null;

        $stakeholders = Gate::forUser($viewer)->allows('viewAny', Contact::class)
            ? $deal->contacts()
                ->visibleTo($viewer)
                ->orderBy('contacts.last_name')
                ->orderBy('contacts.first_name')
                ->get()
                ->map(static fn (Contact $contact): DealStakeholderData => DealStakeholderData::fromContact($contact))
                ->values()
                ->all()
            : [];

        $canUpdate = Gate::forUser($viewer)->allows('update', $deal);
        $canManageCollaborators = Gate::forUser($viewer)->allows('manageCollaborators', $deal);
        $canSubmitHandoff = Gate::forUser($viewer)->allows('submit', [DealHandoff::class, $deal]);
        $collaboratorOptions = $this->entityContext->collaboratorOptions();

        $activeHandoffModel = DealHandoff::query()
            ->where('deal_id', $deal->id)
            ->where('status', '!=', DealHandoffStatusEnum::Rejected->value)
            ->with(DealHandoffQuerySupport::eagerLoadRelations())
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->first();

        $activeHandoff = $activeHandoffModel !== null
            && Gate::forUser($viewer)->allows('view', $activeHandoffModel)
            ? DealHandoffSummaryData::fromModel($activeHandoffModel)
            : null;

        return [
            'deal' => DealDetailData::fromModel($deal, $canViewCompany, $canViewPrimaryContact),
            'company' => $linkedCompany,
            'primaryContact' => $linkedPrimaryContact,
            'pipeline' => PipelineData::fromModel($deal->pipeline),
            'stakeholders' => $stakeholders,
            'collaborators' => $this->entityContext->collaborators($deal),
            'attachments' => $this->entityContext->cleanAttachments($deal),
            'customFieldDefinitions' => $this->dealContext->dealCustomFieldDefinitions((string) $deal->pipeline_id),
            'timeline' => $this->queryTimeline->execute($viewer, 'deal', (string) $deal->id, null, 25),
            'assignableUsers' => $this->entityContext->assignableUsers($viewer, includeAllActive: $canManageCollaborators),
            'assignableAms' => $canSubmitHandoff ? $this->handoffQuerySupport->assignableAmsForViewer($viewer) : [],
            'activeHandoff' => $activeHandoff,
            'companies' => $canUpdate ? $this->entityContext->visibleCompanySummaries($viewer) : [],
            'contacts' => $canUpdate ? $this->dealContext->visibleContactSummaries($viewer) : [],
            'stakeholderRoles' => array_map(
                static fn (DealStakeholderRoleEnum $role): string => $role->value,
                DealStakeholderRoleEnum::cases(),
            ),
            'collaboratorAccessLevels' => $collaboratorOptions['accessLevels'],
            'collaboratorRoleLabels' => $collaboratorOptions['roleLabels'],
            'can' => [
                'update' => $canUpdate,
                'delete' => Gate::forUser($viewer)->allows('delete', $deal),
                'moveStage' => Gate::forUser($viewer)->allows('moveStage', $deal),
                'overrideGates' => Gate::forUser($viewer)->allows('overrideGates', $deal),
                'manageCollaborators' => $canManageCollaborators,
                'submitHandoff' => $canSubmitHandoff,
            ],
        ];
    }
}
