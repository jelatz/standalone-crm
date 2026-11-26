<?php

namespace App\Modules\Projects\Handoffs\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Deals\Models\Deal;
use App\Modules\Projects\Handoffs\Actions\ApproveDealHandoffAction;
use App\Modules\Projects\Handoffs\Actions\ListHandoffQueueAction;
use App\Modules\Projects\Handoffs\Actions\LoadHandoffDetailAction;
use App\Modules\Projects\Handoffs\Actions\RejectDealHandoffAction;
use App\Modules\Projects\Handoffs\Actions\RequestHandoffChangesAction;
use App\Modules\Projects\Handoffs\Actions\ResubmitDealHandoffAction;
use App\Modules\Projects\Handoffs\Actions\SubmitDealHandoffAction;
use App\Modules\Projects\Handoffs\Data\ApproveHandoffData;
use App\Modules\Projects\Handoffs\Data\ReviewHandoffData;
use App\Modules\Projects\Handoffs\Data\SubmitHandoffData;
use App\Modules\Projects\Handoffs\Models\DealHandoff;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class DealHandoffController extends Controller
{
    public function index(
        Request $request,
        ListHandoffQueueAction $listQueue,
    ): Response {
        Gate::forUser($request->user())->authorize('viewAny', DealHandoff::class);

        /** @var User $viewer */
        $viewer = $request->user();
        $statusFilter = is_string($request->query('status')) ? $request->query('status') : null;

        return Inertia::render('projects/pages/HandoffIntakePage', $listQueue->execute($viewer, $statusFilter));
    }

    public function show(
        DealHandoff $handoff,
        Request $request,
        LoadHandoffDetailAction $loadDetail,
    ): Response {
        Gate::forUser($request->user())->authorize('view', $handoff);

        /** @var User $viewer */
        $viewer = $request->user();

        return Inertia::render('projects/pages/HandoffDetailPage', $loadDetail->execute($viewer, $handoff));
    }

    public function store(
        Deal $deal,
        Request $request,
        SubmitDealHandoffAction $submitHandoff,
        LoadHandoffDetailAction $loadDetail,
    ): JsonResponse {
        Gate::forUser($request->user())->authorize('submit', [DealHandoff::class, $deal]);

        /** @var User $actor */
        $actor = $request->user();

        $data = SubmitHandoffData::validateAndCreate($request->all());
        $handoff = $submitHandoff->execute($actor, $deal, $data);

        return response()->json($loadDetail->buildDetailData($handoff, $actor)->toArray(), 201);
    }

    public function resubmit(
        DealHandoff $handoff,
        Request $request,
        ResubmitDealHandoffAction $resubmitHandoff,
        LoadHandoffDetailAction $loadDetail,
    ): JsonResponse {
        Gate::forUser($request->user())->authorize('resubmit', $handoff);

        /** @var User $actor */
        $actor = $request->user();

        $data = SubmitHandoffData::validateAndCreate($request->all());
        $updated = $resubmitHandoff->execute($actor, $handoff, $data);

        return response()->json($loadDetail->buildDetailData($updated, $actor)->toArray(), 200);
    }

    public function requestChanges(
        DealHandoff $handoff,
        Request $request,
        RequestHandoffChangesAction $requestChanges,
        LoadHandoffDetailAction $loadDetail,
    ): JsonResponse {
        Gate::forUser($request->user())->authorize('requestChanges', $handoff);

        /** @var User $actor */
        $actor = $request->user();

        $data = ReviewHandoffData::validateAndCreate($request->all());
        $updated = $requestChanges->execute($actor, $handoff, $data);

        return response()->json($loadDetail->buildDetailData($updated, $actor)->toArray(), 200);
    }

    public function reject(
        DealHandoff $handoff,
        Request $request,
        RejectDealHandoffAction $rejectHandoff,
        LoadHandoffDetailAction $loadDetail,
    ): JsonResponse {
        Gate::forUser($request->user())->authorize('reject', $handoff);

        /** @var User $actor */
        $actor = $request->user();

        $data = ReviewHandoffData::validateAndCreate($request->all());
        $updated = $rejectHandoff->execute($actor, $handoff, $data);

        return response()->json($loadDetail->buildDetailData($updated, $actor)->toArray(), 200);
    }

    public function approve(
        DealHandoff $handoff,
        Request $request,
        ApproveDealHandoffAction $approveHandoff,
        LoadHandoffDetailAction $loadDetail,
    ): JsonResponse {
        Gate::forUser($request->user())->authorize('approve', $handoff);

        /** @var User $actor */
        $actor = $request->user();

        $data = ApproveHandoffData::validateAndCreate($request->all());
        $updated = $approveHandoff->execute($actor, $handoff, $data);

        return response()->json($loadDetail->buildDetailData($updated, $actor)->toArray(), 200);
    }
}
