<?php

namespace App\Modules\Projects\Templates\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Users\Models\User;
use App\Modules\Projects\Templates\Actions\ListProjectTemplatesAction;
use App\Modules\Projects\Templates\Data\ProjectTemplateSummaryData;
use App\Modules\Projects\Templates\Models\ProjectTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ProjectTemplateController extends Controller
{
    public function index(
        Request $request,
        ListProjectTemplatesAction $listTemplates,
    ): JsonResponse {
        Gate::forUser($request->user())->authorize('viewAny', ProjectTemplate::class);

        /** @var User $viewer */
        $viewer = $request->user();
        $includeInactive = $request->boolean('include_inactive')
            && Gate::forUser($viewer)->allows('create', ProjectTemplate::class);

        $templates = $listTemplates->execute($viewer, activeOnly: ! $includeInactive);

        return response()->json([
            'templates' => array_map(
                static fn (ProjectTemplateSummaryData $item): array => $item->toArray(),
                $templates,
            ),
        ], 200);
    }
}
