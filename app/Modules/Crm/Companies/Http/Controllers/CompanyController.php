<?php

namespace App\Modules\Crm\Companies\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Rbac\Actions\GrantEntityCollaboratorAction;
use App\Modules\Core\Rbac\Actions\RevokeEntityCollaboratorAction;
use App\Modules\Core\Rbac\Data\EntityCollaboratorData;
use App\Modules\Core\Rbac\Data\GrantCollaboratorData;
use App\Modules\Core\Users\Enums\UserStatusEnum;
use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Companies\Actions\CreateCompanyAction;
use App\Modules\Crm\Companies\Actions\DeleteCompanyAction;
use App\Modules\Crm\Companies\Actions\ListCompaniesAction;
use App\Modules\Crm\Companies\Actions\UpdateCompanyAction;
use App\Modules\Crm\Companies\Data\CompanyDetailData;
use App\Modules\Crm\Companies\Data\CompanyFilterData;
use App\Modules\Crm\Companies\Data\UpsertCompanyData;
use App\Modules\Crm\Companies\Enums\CompanyLifecycleStageEnum;
use App\Modules\Crm\Companies\Models\Company;
use App\Modules\Crm\Contacts\Data\ContactListItemData;
use App\Modules\Crm\Contacts\Models\Contact;
use App\Modules\Crm\CustomFields\Enums\CustomFieldEntityTypeEnum;
use App\Modules\Crm\Deals\Data\RelatedDealData;
use App\Modules\Crm\Deals\Models\Deal;
use App\Modules\Crm\Shared\Actions\LoadEntityContextAction;
use App\Modules\Crm\Timeline\Actions\QueryEntityTimelineAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class CompanyController extends Controller
{
    public function index(
        Request $request,
        ListCompaniesAction $listCompanies,
        LoadEntityContextAction $context,
    ): Response {
        Gate::forUser($request->user())->authorize('viewAny', Company::class);

        /** @var User $viewer */
        $viewer = $request->user();

        $filter = CompanyFilterData::validateAndCreate($request->query());
        $companies = $listCompanies->execute($viewer, $filter)->withQueryString();

        return Inertia::render('crm/pages/CompaniesIndexPage', [
            'companies' => $companies,
            'filters' => $filter->toArray(),
            'filterDefinitions' => $context->customFieldDefinitions(CustomFieldEntityTypeEnum::Company, filterableOnly: true),
            'customFieldDefinitions' => $context->customFieldDefinitions(CustomFieldEntityTypeEnum::Company),
            'assignableUsers' => $context->assignableUsers($viewer),
            'lifecycleStages' => array_map(
                static fn (CompanyLifecycleStageEnum $stage): string => $stage->value,
                CompanyLifecycleStageEnum::cases(),
            ),
            'can' => [
                'create' => Gate::forUser($viewer)->allows('create', Company::class),
            ],
        ]);
    }

    public function show(
        Company $company,
        Request $request,
        QueryEntityTimelineAction $queryTimeline,
        LoadEntityContextAction $context,
    ): Response {
        Gate::forUser($request->user())->authorize('view', $company);

        /** @var User $viewer */
        $viewer = $request->user();

        $company->load(['owner:id,name', 'accountManager:id,name']);

        $contacts = Contact::query()
            ->visibleTo($viewer)
            ->where('company_id', $company->id)
            ->with(['owner:id,name', 'company:id,name', 'emails'])
            ->orderByDesc('is_primary_contact')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get()
            ->map(static fn (Contact $contact): ContactListItemData => ContactListItemData::fromModel($contact))
            ->values()
            ->all();

        $deals = Deal::query()
            ->visibleTo($viewer)
            ->where('company_id', $company->id)
            ->with(['pipeline:id,name', 'stage:id,name,stage_type', 'owner:id,name', 'accountManager:id,name'])
            ->orderByDesc('updated_at')
            ->get()
            ->map(static fn (Deal $deal): RelatedDealData => RelatedDealData::fromModel($deal))
            ->values()
            ->all();

        $canManageCollaborators = Gate::forUser($viewer)->allows('manageCollaborators', $company);
        $collaboratorOptions = $context->collaboratorOptions();

        return Inertia::render('crm/pages/CompanyDetailPage', [
            'company' => CompanyDetailData::fromModel($company),
            'contacts' => $contacts,
            'deals' => $deals,
            'collaborators' => $context->collaborators($company),
            'attachments' => $context->cleanAttachments($company),
            'customFieldDefinitions' => $context->customFieldDefinitions(CustomFieldEntityTypeEnum::Company),
            'timeline' => $queryTimeline->execute($viewer, 'company', (string) $company->id, null, 25),
            'assignableUsers' => $context->assignableUsers($viewer, includeAllActive: $canManageCollaborators),
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
        ]);
    }

    public function store(
        Request $request,
        CreateCompanyAction $createCompany,
    ): JsonResponse {
        Gate::forUser($request->user())->authorize('create', Company::class);

        /** @var User $actor */
        $actor = $request->user();

        $data = UpsertCompanyData::validateAndCreate($request->all());
        $company = $createCompany->execute($actor, $data);

        return response()->json(CompanyDetailData::fromModel($company)->toArray(), 201);
    }

    public function update(
        Company $company,
        Request $request,
        UpdateCompanyAction $updateCompany,
    ): JsonResponse {
        Gate::forUser($request->user())->authorize('update', $company);

        /** @var User $actor */
        $actor = $request->user();

        $data = UpsertCompanyData::validateAndCreate($request->all());
        $updated = $updateCompany->execute($actor, $company, $data);

        return response()->json(CompanyDetailData::fromModel($updated)->toArray(), 200);
    }

    public function destroy(
        Company $company,
        Request $request,
        DeleteCompanyAction $deleteCompany,
    ): JsonResponse {
        Gate::forUser($request->user())->authorize('delete', $company);

        /** @var User $actor */
        $actor = $request->user();

        $deleteCompany->execute($actor, $company);

        return response()->json(['deleted' => true], 200);
    }

    public function grantCollaborator(
        Company $company,
        Request $request,
        GrantEntityCollaboratorAction $grantCollaborator,
    ): JsonResponse {
        Gate::forUser($request->user())->authorize('manageCollaborators', $company);

        /** @var User $actor */
        $actor = $request->user();

        $data = GrantCollaboratorData::validateAndCreate($request->all());
        $targetUser = User::query()
            ->where('status', UserStatusEnum::Active->value)
            ->findOrFail($data->user_id);

        $collaborator = $grantCollaborator->execute(
            collaboratable: $company,
            user: $targetUser,
            accessLevel: $data->access_level,
            roleLabel: $data->role_label,
            grantedBy: $actor,
        );

        $collaborator->load(['user:id,name,email', 'grantedBy:id,name']);

        return response()->json(EntityCollaboratorData::fromModel($collaborator)->toArray(), 201);
    }

    public function revokeCollaborator(
        Company $company,
        string $user,
        Request $request,
        RevokeEntityCollaboratorAction $revokeCollaborator,
    ): JsonResponse {
        Gate::forUser($request->user())->authorize('manageCollaborators', $company);

        $targetUser = User::withTrashed()->findOrFail($user);
        $revokeCollaborator->execute($company, $targetUser);

        return response()->json(['revoked' => true], 200);
    }
}
