<?php

namespace App\Modules\Crm\Contacts\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Rbac\Data\GrantCollaboratorData;
use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Contacts\Actions\DeleteContactAction;
use App\Modules\Crm\Contacts\Actions\ListContactsAction;
use App\Modules\Crm\Contacts\Actions\LoadContactDetailAction;
use App\Modules\Crm\Contacts\Actions\UpsertContactAction;
use App\Modules\Crm\Contacts\Data\ContactDetailData;
use App\Modules\Crm\Contacts\Data\ContactFilterData;
use App\Modules\Crm\Contacts\Data\UpsertContactData;
use App\Modules\Crm\Contacts\Models\Contact;
use App\Modules\Crm\CustomFields\Enums\CustomFieldEntityTypeEnum;
use App\Modules\Crm\Shared\Actions\LoadEntityContextAction;
use App\Modules\Crm\Shared\Actions\ManageCrmCollaboratorAction;
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

        return Inertia::render('crm/pages/ContactsIndexPage', [
            'contacts' => $contacts,
            'filters' => $filter->toArray(),
            'filterDefinitions' => $context->customFieldDefinitions(CustomFieldEntityTypeEnum::Contact, filterableOnly: true),
            'customFieldDefinitions' => $context->customFieldDefinitions(CustomFieldEntityTypeEnum::Contact),
            'assignableUsers' => $context->assignableUsers($viewer),
            'companies' => $context->visibleCompanySummaries($viewer),
            'can' => [
                'create' => Gate::forUser($viewer)->allows('create', Contact::class),
            ],
        ]);
    }

    public function show(
        Contact $contact,
        Request $request,
        LoadContactDetailAction $loadContactDetail,
    ): Response {
        Gate::forUser($request->user())->authorize('view', $contact);

        /** @var User $viewer */
        $viewer = $request->user();

        return Inertia::render('crm/pages/ContactDetailPage', $loadContactDetail->execute($viewer, $contact));
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

        $canViewCompany = $contact->company !== null
            && Gate::forUser($actor)->allows('view', $contact->company);

        return response()->json(ContactDetailData::fromModel($contact, $canViewCompany)->toArray(), 201);
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
        ManageCrmCollaboratorAction $manageCollaborator,
    ): JsonResponse {
        Gate::forUser($request->user())->authorize('manageCollaborators', $contact);

        /** @var User $actor */
        $actor = $request->user();

        $data = GrantCollaboratorData::validateAndCreate($request->all());
        $collaborator = $manageCollaborator->grant($actor, $contact, $data);

        return response()->json($collaborator->toArray(), 201);
    }

    public function revokeCollaborator(
        Contact $contact,
        string $user,
        Request $request,
        ManageCrmCollaboratorAction $manageCollaborator,
    ): JsonResponse {
        Gate::forUser($request->user())->authorize('manageCollaborators', $contact);

        /** @var User $actor */
        $actor = $request->user();

        $manageCollaborator->revoke($actor, $contact, $user);

        return response()->json(['revoked' => true], 200);
    }
}
