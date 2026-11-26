<?php

namespace App\Modules\Crm\Companies\Actions;

use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Companies\Data\CompanyDetailData;
use App\Modules\Crm\Companies\Enums\CompanyLifecycleStageEnum;
use App\Modules\Crm\Companies\Models\Company;
use App\Modules\Crm\Contacts\Data\ContactListItemData;
use App\Modules\Crm\Contacts\Models\Contact;
use App\Modules\Crm\CustomFields\Enums\CustomFieldEntityTypeEnum;
use App\Modules\Crm\Deals\Models\Deal;
use App\Modules\Crm\Shared\Actions\LoadEntityContextAction;
use App\Modules\Crm\Timeline\Actions\QueryEntityTimelineAction;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Gate;

class LoadCompanyDetailAction
{
    public function __construct(
        protected QueryEntityTimelineAction $queryTimeline,
        protected LoadEntityContextAction $context,
    ) {}

    /**
     * Build the authorized, silo- and permission-scoped Inertia props for CompanyDetailPage.
     *
     * @return array<string, mixed>
     */
    public function execute(User $viewer, Company $company): array
    {
        Gate::forUser($viewer)->authorize('view', $company);

        $company->load(['owner:id,name', 'accountManager:id,name']);

        $contacts = Gate::forUser($viewer)->allows('viewAny', Contact::class)
            ? Contact::query()
                ->visibleTo($viewer)
                ->where('company_id', $company->id)
                ->with(['owner:id,name', 'company:id,name', 'emails'])
                ->orderByDesc('is_primary_contact')
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->get()
                ->map(static fn (Contact $contact): ContactListItemData => ContactListItemData::fromModel($contact))
                ->values()
                ->all()
            : [];

        $dealModels = Gate::forUser($viewer)->allows('viewAny', Deal::class)
            ? Deal::query()
                ->visibleTo($viewer)
                ->where('company_id', $company->id)
                ->with(['pipeline:id,name', 'stage:id,name,stage_type,win_probability', 'owner:id,name', 'accountManager:id,name'])
                ->orderByDesc('updated_at')
                ->get()
            : new EloquentCollection;

        $deals = $this->context->relatedDeals($viewer, $dealModels);

        $canManageCollaborators = Gate::forUser($viewer)->allows('manageCollaborators', $company);
        $collaboratorOptions = $this->context->collaboratorOptions();

        return [
            'company' => CompanyDetailData::fromModel($company),
            'contacts' => $contacts,
            'deals' => $deals,
            'collaborators' => $this->context->collaborators($company),
            'attachments' => $this->context->cleanAttachments($company),
            'customFieldDefinitions' => $this->context->customFieldDefinitions(CustomFieldEntityTypeEnum::Company),
            'timeline' => $this->queryTimeline->execute($viewer, 'company', (string) $company->id, null, 25),
            'assignableUsers' => $this->context->assignableUsers($viewer, includeAllActive: $canManageCollaborators),
            'lifecycleStages' => array_map(
                static fn (CompanyLifecycleStageEnum $stage): string => $stage->value,
                CompanyLifecycleStageEnum::cases(),
            ),
            'collaboratorAccessLevels' => $collaboratorOptions['accessLevels'],
            'collaboratorRoleLabels' => $collaboratorOptions['roleLabels'],
            'can' => [
                'update' => Gate::forUser($viewer)->allows('update', $company),
                'delete' => Gate::forUser($viewer)->allows('delete', $company),
                'manageCollaborators' => $canManageCollaborators,
            ],
        ];
    }
}
