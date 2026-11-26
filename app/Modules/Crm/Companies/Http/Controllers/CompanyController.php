<?php

namespace App\Modules\Crm\Companies\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Rbac\Data\GrantCollaboratorData;
use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Companies\Actions\CreateCompanyAction;
use App\Modules\Crm\Companies\Actions\DeleteCompanyAction;
use App\Modules\Crm\Companies\Actions\ListCompaniesAction;
use App\Modules\Crm\Companies\Actions\LoadCompanyDetailAction;
use App\Modules\Crm\Companies\Actions\UpdateCompanyAction;
use App\Modules\Crm\Companies\Data\CompanyDetailData;
use App\Modules\Crm\Companies\Data\CompanyFilterData;
use App\Modules\Crm\Companies\Data\UpsertCompanyData;
use App\Modules\Crm\Companies\Enums\CompanyLifecycleStageEnum;
use App\Modules\Crm\Companies\Models\Company;
use App\Modules\Crm\CustomFields\Enums\CustomFieldEntityTypeEnum;
use App\Modules\Crm\Shared\Actions\LoadEntityContextAction;
use App\Modules\Crm\Shared\Actions\ManageCrmCollaboratorAction;
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
        LoadCompanyDetailAction $loadCompanyDetail,
    ): Response {
        Gate::forUser($request->user())->authorize('view', $company);

        /** @var User $viewer */
        $viewer = $request->user();

        return Inertia::render('crm/pages/CompanyDetailPage', $loadCompanyDetail->execute($viewer, $company));
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
        ManageCrmCollaboratorAction $manageCollaborator,
    ): JsonResponse {
        Gate::forUser($request->user())->authorize('manageCollaborators', $company);

        /** @var User $actor */
        $actor = $request->user();

        $data = GrantCollaboratorData::validateAndCreate($request->all());
        $collaborator = $manageCollaborator->grant($actor, $company, $data);

        return response()->json($collaborator->toArray(), 201);
    }

    public function revokeCollaborator(
        Company $company,
        string $user,
        Request $request,
        ManageCrmCollaboratorAction $manageCollaborator,
    ): JsonResponse {
        Gate::forUser($request->user())->authorize('manageCollaborators', $company);

        /** @var User $actor */
        $actor = $request->user();

        $manageCollaborator->revoke($actor, $company, $user);

        return response()->json(['revoked' => true], 200);
    }
}
