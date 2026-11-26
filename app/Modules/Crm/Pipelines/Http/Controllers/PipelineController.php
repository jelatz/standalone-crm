<?php

namespace App\Modules\Crm\Pipelines\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\CustomFields\Data\CustomFieldDefinitionData;
use App\Modules\Crm\CustomFields\Models\CustomFieldDefinition;
use App\Modules\Crm\Pipelines\Actions\DeletePipelineStageAction;
use App\Modules\Crm\Pipelines\Actions\UpsertPipelineAction;
use App\Modules\Crm\Pipelines\Actions\UpsertPipelineStageAction;
use App\Modules\Crm\Pipelines\Data\PipelineData;
use App\Modules\Crm\Pipelines\Data\PipelineStageData;
use App\Modules\Crm\Pipelines\Data\UpsertPipelineData;
use App\Modules\Crm\Pipelines\Data\UpsertPipelineStageData;
use App\Modules\Crm\Pipelines\Models\Pipeline;
use App\Modules\Crm\Pipelines\Models\PipelineStage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class PipelineController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::forUser($request->user())->authorize('viewAny', Pipeline::class);

        $pipelines = Pipeline::query()
            ->with('stages.requiredCustomFields')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(static fn (Pipeline $pipeline): PipelineData => PipelineData::fromModel($pipeline))
            ->values()
            ->all();

        $customFieldDefinitions = CustomFieldDefinition::query()
            ->orderBy('entity_type')
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get()
            ->map(static fn (CustomFieldDefinition $def): CustomFieldDefinitionData => CustomFieldDefinitionData::fromModel($def))
            ->values()
            ->all();

        return Inertia::render('crm/pages/PipelineSettingsPage', [
            'pipelines' => $pipelines,
            'customFieldDefinitions' => $customFieldDefinitions,
            'allowedCoreFields' => UpsertPipelineStageData::ALLOWED_REQUIRED_CORE_FIELDS,
            'canManage' => Gate::forUser($request->user())->allows('create', Pipeline::class),
        ]);
    }

    public function store(
        Request $request,
        UpsertPipelineAction $upsertPipeline,
    ): JsonResponse {
        Gate::forUser($request->user())->authorize('create', Pipeline::class);

        $data = UpsertPipelineData::validateAndCreate($request->all());
        $pipeline = $upsertPipeline->execute($data);

        return response()->json(PipelineData::fromModel($pipeline)->toArray(), 201);
    }

    public function update(
        Pipeline $pipeline,
        Request $request,
        UpsertPipelineAction $upsertPipeline,
    ): JsonResponse {
        Gate::forUser($request->user())->authorize('update', $pipeline);

        $data = UpsertPipelineData::validateAndCreate($request->all());
        $updated = $upsertPipeline->execute($data, $pipeline);

        return response()->json(PipelineData::fromModel($updated)->toArray(), 200);
    }

    public function storeStage(
        Pipeline $pipeline,
        Request $request,
        UpsertPipelineStageAction $upsertStage,
    ): JsonResponse {
        Gate::forUser($request->user())->authorize('update', $pipeline);

        $data = UpsertPipelineStageData::validateAndCreate($request->all());
        $stage = $upsertStage->execute($pipeline, $data);

        return response()->json(PipelineStageData::fromModel($stage)->toArray(), 201);
    }

    public function updateStage(
        Pipeline $pipeline,
        PipelineStage $stage,
        Request $request,
        UpsertPipelineStageAction $upsertStage,
    ): JsonResponse {
        Gate::forUser($request->user())->authorize('update', $pipeline);

        $data = UpsertPipelineStageData::validateAndCreate($request->all());
        $updated = $upsertStage->execute($pipeline, $data, $stage);

        return response()->json(PipelineStageData::fromModel($updated)->toArray(), 200);
    }

    public function destroyStage(
        Pipeline $pipeline,
        PipelineStage $stage,
        Request $request,
        DeletePipelineStageAction $deleteStage,
    ): JsonResponse {
        Gate::forUser($request->user())->authorize('update', $pipeline);

        $deleteStage->execute($pipeline, $stage);

        return response()->json(['deleted' => true], 200);
    }
}
