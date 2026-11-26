<?php

namespace App\Modules\Crm\Contacts\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Rbac\Actions\GrantEntityCollaboratorAction;
use App\Modules\Core\Rbac\Actions\RevokeEntityCollaboratorAction;
use App\Modules\Core\Rbac\Data\EntityCollaboratorData;
use App\Modules\Core\Rbac\Data\GrantCollaboratorData;
use App\Modules\Core\Users\Enums\UserStatusEnum;
use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Companies\Models\Company;
use App\Modules\Crm\Contacts\Actions\DeleteContactAction;
use App\Modules\Crm\Contacts\Actions\ListContactsAction;
use App\Modules\Crm\Contacts\Actions\UpsertContactAction;
use App\Modules\Crm\Contacts\Data\ContactDetailData;
use App\Modules\Crm\Contacts\Data\ContactFilterData;
use App\Modules\Crm\Contacts\Data\UpsertContactData;
use App\Modules\Crm\Contacts\Models\Contact;
use App\Modules\Crm\CustomFields\Enums\CustomFieldEntityTypeEnum;
use App\Modules\Crm\Deals\Data\RelatedDealData;
use App\Modules\Crm\Deals\Models\Deal;
use App\Modules\Crm\Shared\Actions\LoadEntityContextAction;
use App\Modules\Crm\Timeline\Actions\QueryEntityTimelineAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ContactController extends Controller
{
    public function index(
        Request $request,
        ListContactsAction $listContacts,
        LoadEntityContextAction $context,
    ): Response {
        Gate::forUser($request->user())->authorize('viewAny', Contact::class);

        /** @var User $viewer */
        $viewer = $request->user();

        $filter = ContactFilterData::validateAndCreate($request->query());
        $contacts = $listContacts->execute($viewer, $filter)->withQueryString();

        $companies = Company::query()
            ->visibleTo($viewer)
            ->orderBy('name')
            ->get(['id', 'name', 'domain'])
            ->map(static fn (Company $company): array => [
                'id' => (string) $company->id,
                'name' => $company->name,
                'domain' => $company->domain,
            ])
            ->values()
            ->all();

        return Inertia::render('crm/pages/ContactsIndexPage', [
            'contacts' => $contacts,
            'filters' => $filter->toArray(),
            'filterDefinitions' => $context->customFieldDefinitions(CustomFieldEntityTypeEnum::Contact, filterableOnly: true),
            'customFieldDefinitions' => $context->customFieldDefinitions(CustomFieldEntityTypeEnum::Contact),
            'assignableUsers' => $context->assignableUsers($viewer),
            'companies' => $companies,
            'can' => [
                'create' => Gate::forUser($viewer)->allows('create', Contact::class),
            ],
        ]);
    }

    public function show(
        Contact $contact,
        Request $request,
        QueryEntityTimelineAction $queryTimeline,
        LoadEntityContextAction $context,
    ): Response {
        Gate::forUser($request->user())->authorize('view', $contact);

        /** @var User $viewer */
        $viewer = $request->user();

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

        $deals = Deal::query()
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
            ->map(static fn (Deal $deal): RelatedDealData => RelatedDealData::fromModel($deal))
            ->values()
            ->all();

        $canUpdate = Gate::forUser($viewer)->allows('update', $contact);
        $canManageCollaborators = Gate::forUser($viewer)->allows('manageCollaborators', $contact);
        $collaboratorOptions = $context->collaboratorOptions();

        $companies = $canUpdate
            ? Company::query()
                ->visibleTo($viewer)
                ->orderBy('name')
                ->get(['id', 'name', 'domain'])
                ->map(static fn (Company $company): array => [
                    'id' => (string) $company->id,
                    'name' => $company->name,
                    'domain' => $company->domain,
                ])
                ->values()
                ->all()
            : [];

        return Inertia::render('crm/pages/ContactDetailPage', [
            'contact' => ContactDetailData::fromModel($contact, $canViewCompany),
            'company' => $linkedCompany,
            'deals' => $deals,
            'collaborators' => $context->collaborators($contact),
            'attachments' => $context->cleanAttachments($contact),
            'customFieldDefinitions' => $context->customFieldDefinitions(CustomFieldEntityTypeEnum::Contact),
            'timeline' => $queryTimeline->execute($viewer, 'contact', (string) $contact->id, null, 25),
            'assignableUsers' => $context->assignableUsers($viewer, includeAllActive: $canManageCollaborators),
            'companies' => $companies,
            'collaboratorAccessLevels' => $collaboratorOptions['accessLevels'],
            'collaboratorRoleLabels' => $collaboratorOptions['roleLabels'],
            'can' => [
                'update' => $canUpdate,
                'delete' => Gate::forUser($viewer)->allows('delete', $contact),
                'manageCollaborators' => $canManageCollaborators,
            ],
        ]);
    }

    public function store(
        Request $request,
        UpsertContactAction $upsertContact,
    ): JsonResponse {
        Gate::forUser($request->user())->authorize('create', Contact::class);

        /** @var User $actor */
        $actor = $request->user();

        $data = UpsertContactData::validateAndCreate($request->all());
        $contact = $upsertContact->execute($actor, $data);

        return response()->json(ContactDetailData::fromModel($contact)->toArray(), 201);
    }

    public function update(
        Contact $contact,
        Request $request,
        UpsertContactAction $upsertContact,
    ): JsonResponse {
        Gate::forUser($request->user())->authorize('update', $contact);

        /** @var User $actor */
        $actor = $request->user();

        $data = UpsertContactData::validateAndCreate($request->all());
        $updated = $upsertContact->execute($actor, $data, $contact);

        $canViewCompany = $updated->company !== null
            && Gate::forUser($actor)->allows('view', $updated->company);

        return response()->json(ContactDetailData::fromModel($updated, $canViewCompany)->toArray(), 200);
    }

    public function destroy(
        Contact $contact,
        Request $request,
        DeleteContactAction $deleteContact,
    ): JsonResponse {
        Gate::forUser($request->user())->authorize('delete', $contact);

        /** @var User $actor */
        $actor = $request->user();

        $deleteContact->execute($actor, $contact);

        return response()->json(['deleted' => true], 200);
    }

    public function grantCollaborator(
        Contact $contact,
        Request $request,
        GrantEntityCollaboratorAction $grantCollaborator,
    ): JsonResponse {
        Gate::forUser($request->user())->authorize('manageCollaborators', $contact);

        /** @var User $actor */
        $actor = $request->user();

        $data = GrantCollaboratorData::validateAndCreate($request->all());
        $targetUser = User::query()
            ->where('status', UserStatusEnum::Active->value)
            ->findOrFail($data->user_id);

        $collaborator = $grantCollaborator->execute(
            collaboratable: $contact,
            user: $targetUser,
            accessLevel: $data->access_level,
            roleLabel: $data->role_label,
            grantedBy: $actor,
        );

        $collaborator->load(['user:id,name,email', 'grantedBy:id,name']);

        return response()->json(EntityCollaboratorData::fromModel($collaborator)->toArray(), 201);
    }

    public function revokeCollaborator(
        Contact $contact,
        string $user,
        Request $request,
        RevokeEntityCollaboratorAction $revokeCollaborator,
    ): JsonResponse {
        Gate::forUser($request->user())->authorize('manageCollaborators', $contact);

        $targetUser = User::withTrashed()->findOrFail($user);
        $revokeCollaborator->execute($contact, $targetUser);

        return response()->json(['revoked' => true], 200);
    }
}
