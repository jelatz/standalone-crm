<?php

namespace App\Modules\Crm\Leads\Actions;

use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Companies\Models\Company;
use App\Modules\Crm\Contacts\Models\Contact;
use App\Modules\Crm\CustomFields\Enums\CustomFieldEntityTypeEnum;
use App\Modules\Crm\Deals\Actions\LoadDealContextAction;
use App\Modules\Crm\Deals\Models\Deal;
use App\Modules\Crm\Leads\Data\LeadDetailData;
use App\Modules\Crm\Leads\Enums\LeadSourceEnum;
use App\Modules\Crm\Leads\Enums\LeadStatusEnum;
use App\Modules\Crm\Leads\Models\Lead;
use App\Modules\Crm\Shared\Actions\LoadEntityContextAction;
use App\Modules\Crm\Timeline\Actions\QueryEntityTimelineAction;
use Illuminate\Support\Facades\Gate;

class LoadLeadDetailAction
{
    public function __construct(
        protected QueryEntityTimelineAction $queryTimeline,
        protected LoadEntityContextAction $entityContext,
        protected LoadDealContextAction $dealContext,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(User $viewer, Lead $lead): array
    {
        Gate::forUser($viewer)->authorize('view', $lead);

        $lead->load([
            'owner:id,name',
            'convertedCompany:id,name,domain,owner_id,account_manager_id',
            'convertedContact:id,first_name,last_name,full_name,primary_email,owner_id',
            'convertedDeal:id,title,amount_cents,currency,owner_id,account_manager_id',
        ]);

        $canViewCompany = $lead->convertedCompany !== null
            && Gate::forUser($viewer)->allows('view', $lead->convertedCompany);

        $convertedCompany = $canViewCompany && $lead->convertedCompany !== null
            ? [
                'id' => (string) $lead->convertedCompany->id,
                'name' => $lead->convertedCompany->name,
                'domain' => $lead->convertedCompany->domain,
            ]
            : null;

        $canViewContact = $lead->convertedContact !== null
            && Gate::forUser($viewer)->allows('view', $lead->convertedContact);

        $convertedContact = $canViewContact && $lead->convertedContact !== null
            ? [
                'id' => (string) $lead->convertedContact->id,
                'full_name' => $lead->convertedContact->full_name
                    ?? trim($lead->convertedContact->first_name.' '.$lead->convertedContact->last_name),
                'primary_email' => $lead->convertedContact->primary_email,
            ]
            : null;

        $canViewDeal = $lead->convertedDeal !== null
            && Gate::forUser($viewer)->allows('view', $lead->convertedDeal);

        $convertedDeal = $canViewDeal && $lead->convertedDeal !== null
            ? [
                'id' => (string) $lead->convertedDeal->id,
                'title' => $lead->convertedDeal->title,
                'amount_cents' => $lead->convertedDeal->amount_cents,
                'currency' => $lead->convertedDeal->currency,
            ]
            : null;

        $canUpdate = Gate::forUser($viewer)->allows('update', $lead);
        $canConvert = $lead->status !== LeadStatusEnum::Converted
            && $lead->converted_at === null
            && Gate::forUser($viewer)->allows('create', Contact::class)
            && Gate::forUser($viewer)->allows('convert', $lead);
        $canManageCollaborators = Gate::forUser($viewer)->allows('manageCollaborators', $lead);
        $collaboratorOptions = $this->entityContext->collaboratorOptions();

        return [
            'lead' => LeadDetailData::fromModel($lead, $canViewCompany, $canViewContact, $canViewDeal),
            'convertedCompany' => $convertedCompany,
            'convertedContact' => $convertedContact,
            'convertedDeal' => $convertedDeal,
            'collaborators' => $this->entityContext->collaborators($lead),
            'attachments' => $this->entityContext->cleanAttachments($lead),
            'customFieldDefinitions' => $this->entityContext->customFieldDefinitions(CustomFieldEntityTypeEnum::Lead),
            'timeline' => $this->queryTimeline->execute($viewer, 'lead', (string) $lead->id, null, 25),
            'assignableUsers' => $this->entityContext->assignableUsers($viewer, includeAllActive: $canManageCollaborators),
            'companies' => $canConvert ? $this->entityContext->visibleCompanySummaries($viewer) : [],
            'pipelines' => $canConvert ? $this->dealContext->activePipelines() : [],
            'statuses' => array_map(static fn (LeadStatusEnum $status): string => $status->value, LeadStatusEnum::cases()),
            'sources' => array_map(static fn (LeadSourceEnum $source): string => $source->value, LeadSourceEnum::cases()),
            'collaboratorAccessLevels' => $collaboratorOptions['accessLevels'],
            'collaboratorRoleLabels' => $collaboratorOptions['roleLabels'],
            'can' => [
                'update' => $canUpdate,
                'delete' => Gate::forUser($viewer)->allows('delete', $lead),
                'convert' => $canConvert,
                'manageCollaborators' => $canManageCollaborators,
                'createCompany' => Gate::forUser($viewer)->allows('create', Company::class),
                'createContact' => Gate::forUser($viewer)->allows('create', Contact::class),
                'createDeal' => Gate::forUser($viewer)->allows('create', Deal::class),
            ],
        ];
    }
}
