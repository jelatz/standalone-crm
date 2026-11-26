<?php

namespace App\Modules\Crm\Deals\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Rbac\Data\GrantCollaboratorData;
use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Deals\Actions\BuildDealDetailDataAction;
use App\Modules\Crm\Deals\Actions\CreateDealAction;
use App\Modules\Crm\Deals\Actions\DeleteDealAction;
use App\Modules\Crm\Deals\Actions\LoadDealDetailAction;
use App\Modules\Crm\Deals\Actions\LoadDealsIndexAction;
use App\Modules\Crm\Deals\Actions\MoveDealStageAction;
use App\Modules\Crm\Deals\Actions\SyncDealContactsAction;
use App\Modules\Crm\Deals\Actions\UpdateDealAction;
use App\Modules\Crm\Deals\Data\CreateDealData;
use App\Modules\Crm\Deals\Data\DealFilterData;
use App\Modules\Crm\Deals\Data\DealStakeholderData;
use App\Modules\Crm\Deals\Data\MoveDealStageData;
use App\Modules\Crm\Deals\Data\SyncDealContactsData;
use App\Modules\Crm\Deals\Data\UpdateDealData;
use App\Modules\Crm\Deals\Models\Deal;
use App\Modules\Crm\Shared\Actions\ManageCrmCollaboratorAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class DealController extends Controller
{
    public function index(
        Request $request,
        LoadDealsIndexAction $loadDealsIndex,
    ): Response {
        Gate::forUser($request->user())->authorize('viewAny', Deal::class);

        /** @var User $viewer */
        $viewer = $request->user();

        $filter = DealFilterData::validateAndCreate($request->query());

        return Inertia::render('crm/pages/DealsIndexPage', $loadDealsIndex->execute($viewer, $filter));
    }

    public function show(
        Deal $deal,
        Request $request,
        LoadDealDetailAction $loadDealDetail,
    ): Response {
        Gate::forUser($request->user())->authorize('view', $deal);

        /** @var User $viewer */
        $viewer = $request->user();

        return Inertia::render('crm/pages/DealDetailPage', $loadDealDetail->execute($viewer, $deal));
    }

    public function store(
        Request $request,
        CreateDealAction $createDeal,
        BuildDealDetailDataAction $buildDealDetail,
    ): JsonResponse {
        Gate::forUser($request->user())->authorize('create', Deal::class);

        /** @var User $actor */
        $actor = $request->user();

        $data = CreateDealData::validateAndCreate($request->all());
        $deal = $createDeal->execute($actor, $data);

        return response()->json($buildDealDetail->execute($actor, $deal)->toArray(), 201);
    }

    public function update(
        Deal $deal,
        Request $request,
        UpdateDealAction $updateDeal,
        BuildDealDetailDataAction $buildDealDetail,
    ): JsonResponse {
        Gate::forUser($request->user())->authorize('update', $deal);

        /** @var User $actor */
        $actor = $request->user();

        $data = UpdateDealData::validateAndCreate($request->all());
        $updated = $updateDeal->execute($actor, $deal, $data);

        return response()->json($buildDealDetail->execute($actor, $updated)->toArray(), 200);
    }

    public function moveStage(
        Deal $deal,
        Request $request,
        MoveDealStageAction $moveDealStage,
        BuildDealDetailDataAction $buildDealDetail,
    ): JsonResponse {
        Gate::forUser($request->user())->authorize('moveStage', $deal);

        /** @var User $actor */
        $actor = $request->user();

        $data = MoveDealStageData::validateAndCreate($request->all());
        $moved = $moveDealStage->execute($actor, $deal, $data);

        return response()->json($buildDealDetail->execute($actor, $moved)->toArray(), 200);
    }

    public function destroy(
        Deal $deal,
        Request $request,
        DeleteDealAction $deleteDeal,
    ): JsonResponse {
        Gate::forUser($request->user())->authorize('delete', $deal);

        /** @var User $actor */
        $actor = $request->user();

        $deleteDeal->execute($actor, $deal);

        return response()->json(['deleted' => true], 200);
    }

    public function syncContacts(
        Deal $deal,
        Request $request,
        SyncDealContactsAction $syncDealContacts,
    ): JsonResponse {
        Gate::forUser($request->user())->authorize('update', $deal);

        /** @var User $actor */
        $actor = $request->user();

        $data = SyncDealContactsData::validateAndCreate($request->all());
        $stakeholders = $syncDealContacts->execute($actor, $deal, $data);

        return response()->json([
            'contacts' => array_map(
                static fn (DealStakeholderData $item): array => $item->toArray(),
                $stakeholders,
            ),
        ], 200);
    }

    public function grantCollaborator(
        Deal $deal,
        Request $request,
        ManageCrmCollaboratorAction $manageCollaborator,
    ): JsonResponse {
        Gate::forUser($request->user())->authorize('manageCollaborators', $deal);

        /** @var User $actor */
        $actor = $request->user();

        $data = GrantCollaboratorData::validateAndCreate($request->all());
        $collaborator = $manageCollaborator->grant($actor, $deal, $data);

        return response()->json($collaborator->toArray(), 201);
    }

    public function revokeCollaborator(
        Deal $deal,
        string $user,
        Request $request,
        ManageCrmCollaboratorAction $manageCollaborator,
    ): JsonResponse {
        Gate::forUser($request->user())->authorize('manageCollaborators', $deal);

        /** @var User $actor */
        $actor = $request->user();

        $manageCollaborator->revoke($actor, $deal, $user);

        return response()->json(['revoked' => true], 200);
    }
}
