<?php

namespace App\Modules\Crm\Contacts\Actions;

use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Contacts\Data\ContactDetailData;
use App\Modules\Crm\Contacts\Models\Contact;
use App\Modules\Crm\CustomFields\Enums\CustomFieldEntityTypeEnum;
use App\Modules\Crm\Deals\Models\Deal;
use App\Modules\Crm\Shared\Actions\LoadEntityContextAction;
use App\Modules\Crm\Timeline\Actions\QueryEntityTimelineAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Gate;

class LoadContactDetailAction
{
    public function __construct(
        protected QueryEntityTimelineAction $queryTimeline,
        protected LoadEntityContextAction $context,
    ) {}

    /**
     * Build the authorized, silo- and permission-scoped Inertia props for ContactDetailPage.
     *
     * @return array<string, mixed>
     */
    public function execute(User $viewer, Contact $contact): array
    {
        Gate::forUser($viewer)->authorize('view', $contact);

        $contact->load(['owner:id,name', 'company:id,name,domain,lifecycle_stage,owner_id,account_manager_id', 'emails']);

        $canViewCompany = $contact->company !== null
            && Gate::forUser($viewer)->allows('view', $contact->company);

        $linkedCompany = $canViewCompany && $contact->company !== null
            ? [
                'id' => (string) $contact->company->id,
                'name' => $contact->company->name,
                'domain' => $contact->company->domain,
                'lifecycle_stage' => $contact->company->lifecycle_stage->value,
            ]
            : null;

        $dealModels = Gate::forUser($viewer)->allows('viewAny', Deal::class)
            ? Deal::query()
                ->visibleTo($viewer)
                ->where(function (Builder $sub) use ($contact): void {
                    $sub->where('deals.primary_contact_id', $contact->id)
                        ->orWhereExists(function (QueryBuilder $pivot) use ($contact): void {
                            $pivot->selectRaw('1')
                                ->from('deal_contacts')
                                ->whereColumn('deal_contacts.deal_id', '=', 'deals.id')
                                ->where('deal_contacts.contact_id', '=', $contact->id);
                        });
                })
                ->with(['pipeline:id,name', 'stage:id,name,stage_type', 'owner:id,name', 'accountManager:id,name'])
                ->orderByDesc('updated_at')
                ->get()
            : new EloquentCollection;

        $deals = $this->context->relatedDeals($viewer, $dealModels);

        $canUpdate = Gate::forUser($viewer)->allows('update', $contact);
        $canManageCollaborators = Gate::forUser($viewer)->allows('manageCollaborators', $contact);
        $collaboratorOptions = $this->context->collaboratorOptions();

        return [
            'contact' => ContactDetailData::fromModel($contact, $canViewCompany),
            'company' => $linkedCompany,
            'deals' => $deals,
            'collaborators' => $this->context->collaborators($contact),
            'attachments' => $this->context->cleanAttachments($contact),
            'customFieldDefinitions' => $this->context->customFieldDefinitions(CustomFieldEntityTypeEnum::Contact),
            'timeline' => $this->queryTimeline->execute($viewer, 'contact', (string) $contact->id, null, 25),
            'assignableUsers' => $this->context->assignableUsers($viewer, includeAllActive: $canManageCollaborators),
            'companies' => $canUpdate ? $this->context->visibleCompanySummaries($viewer) : [],
            'collaboratorAccessLevels' => $collaboratorOptions['accessLevels'],
            'collaboratorRoleLabels' => $collaboratorOptions['roleLabels'],
            'can' => [
                'update' => $canUpdate,
                'delete' => Gate::forUser($viewer)->allows('delete', $contact),
                'manageCollaborators' => $canManageCollaborators,
            ],
        ];
    }
}
