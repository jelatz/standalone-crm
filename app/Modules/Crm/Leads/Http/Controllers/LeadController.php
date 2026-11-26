<?php

namespace App\Modules\Crm\Leads\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Rbac\Data\GrantCollaboratorData;
use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\CustomFields\Enums\CustomFieldEntityTypeEnum;
use App\Modules\Crm\Leads\Actions\ConvertLeadAction;
use App\Modules\Crm\Leads\Actions\CreateLeadAction;
use App\Modules\Crm\Leads\Actions\DeleteLeadAction;
use App\Modules\Crm\Leads\Actions\ListLeadsAction;
use App\Modules\Crm\Leads\Actions\LoadLeadDetailAction;
use App\Modules\Crm\Leads\Actions\UpdateLeadAction;
use App\Modules\Crm\Leads\Data\ConvertLeadData;
use App\Modules\Crm\Leads\Data\LeadDetailData;
use App\Modules\Crm\Leads\Data\LeadFilterData;
use App\Modules\Crm\Leads\Data\UpsertLeadData;
use App\Modules\Crm\Leads\Enums\LeadSourceEnum;
use App\Modules\Crm\Leads\Enums\LeadStatusEnum;
use App\Modules\Crm\Leads\Models\Lead;
use App\Modules\Crm\Shared\Actions\LoadEntityContextAction;
use App\Modules\Crm\Shared\Actions\ManageCrmCollaboratorAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class LeadController extends Controller
{
    public function index(
        Request $request,
        ListLeadsAction $listLeads,
        LoadEntityContextAction $context,
    ): Response {
        Gate::forUser($request->user())->authorize('viewAny', Lead::class);

        /** @var User $viewer */
        $viewer = $request->user();

        $filter = LeadFilterData::validateAndCreate($request->query());
        $leads = $listLeads->execute($viewer, $filter)->withQueryString();

        return Inertia::render('crm/pages/LeadsIndexPage', [
            'leads' => $leads,
            'statusCounts' => $listLeads->statusCounts($viewer, $filter),
            'filters' => $filter->toArray(),
            'filterDefinitions' => $context->customFieldDefinitions(CustomFieldEntityTypeEnum::Lead, filterableOnly: true),
            'customFieldDefinitions' => $context->customFieldDefinitions(CustomFieldEntityTypeEnum::Lead),
            'assignableUsers' => $context->assignableUsers($viewer),
            'statuses' => array_map(static fn (LeadStatusEnum $status): string => $status->value, LeadStatusEnum::cases()),
            'sources' => array_map(static fn (LeadSourceEnum $source): string => $source->value, LeadSourceEnum::cases()),
            'can' => [
                'create' => Gate::forUser($viewer)->allows('create', Lead::class),
                'convert' => Gate::forUser($viewer)->allows('convertAny', Lead::class),
            ],
        ]);
    }

    public function show(
        Lead $lead,
        Request $request,
        LoadLeadDetailAction $loadLeadDetail,
    ): Response {
        Gate::forUser($request->user())->authorize('view', $lead);

        /** @var User $viewer */
        $viewer = $request->user();

        return Inertia::render('crm/pages/LeadDetailPage', $loadLeadDetail->execute($viewer, $lead));
    }

    public function store(
        Request $request,
        CreateLeadAction $createLead,
    ): JsonResponse {
        Gate::forUser($request->user())->authorize('create', Lead::class);

        /** @var User $actor */
        $actor = $request->user();

        $data = UpsertLeadData::validateAndCreate($request->all());
        $lead = $createLead->execute($actor, $data);

        return response()->json(LeadDetailData::fromModel($lead, false, false, false)->toArray(), 201);
    }

    public function update(
        Lead $lead,
        Request $request,
        UpdateLeadAction $updateLead,
    ): JsonResponse {
        Gate::forUser($request->user())->authorize('update', $lead);

        /** @var User $actor */
        $actor = $request->user();

        $data = UpsertLeadData::validateAndCreate($request->all());
        $updated = $updateLead->execute($actor, $lead, $data);

        $updated->load([
            'convertedCompany:id,owner_id,account_manager_id',
            'convertedContact:id,owner_id',
            'convertedDeal:id,owner_id,account_manager_id',
        ]);

        $canViewCompany = $updated->convertedCompany !== null
            && Gate::forUser($actor)->allows('view', $updated->convertedCompany);
        $canViewContact = $updated->convertedContact !== null
            && Gate::forUser($actor)->allows('view', $updated->convertedContact);
        $canViewDeal = $updated->convertedDeal !== null
            && Gate::forUser($actor)->allows('view', $updated->convertedDeal);

        return response()->json(
            LeadDetailData::fromModel($updated, $canViewCompany, $canViewContact, $canViewDeal)->toArray(),
            200,
        );
    }

    public function destroy(
        Lead $lead,
        Request $request,
        DeleteLeadAction $deleteLead,
    ): JsonResponse {
        Gate::forUser($request->user())->authorize('delete', $lead);

        /** @var User $actor */
        $actor = $request->user();

        $deleteLead->execute($actor, $lead);

        return response()->json(['deleted' => true], 200);
    }

    public function convert(
        Lead $lead,
        Request $request,
        ConvertLeadAction $convertLead,
    ): JsonResponse {
        Gate::forUser($request->user())->authorize('convert', $lead);

        /** @var User $actor */
        $actor = $request->user();

        $data = ConvertLeadData::validateAndCreate($request->all());
        $result = $convertLead->execute($actor, $lead, $data);

        return response()->json($result->toArray(), 201);
    }

    public function grantCollaborator(
        Lead $lead,
        Request $request,
        ManageCrmCollaboratorAction $manageCollaborator,
    ): JsonResponse {
        Gate::forUser($request->user())->authorize('manageCollaborators', $lead);

        /** @var User $actor */
        $actor = $request->user();

        $data = GrantCollaboratorData::validateAndCreate($request->all());
        $collaborator = $manageCollaborator->grant($actor, $lead, $data);

        return response()->json($collaborator->toArray(), 201);
    }

    public function revokeCollaborator(
        Lead $lead,
        string $user,
        Request $request,
        ManageCrmCollaboratorAction $manageCollaborator,
    ): JsonResponse {
        Gate::forUser($request->user())->authorize('manageCollaborators', $lead);

        /** @var User $actor */
        $actor = $request->user();

        $manageCollaborator->revoke($actor, $lead, $user);

        return response()->json(['revoked' => true], 200);
    }
}
