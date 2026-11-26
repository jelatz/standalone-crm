<?php

namespace App\Modules\Crm\Timeline\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Timeline\Actions\QueryEntityTimelineAction;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class TimelineController extends Controller
{
    public function index(
        Request $request,
        QueryEntityTimelineAction $queryTimeline,
    ): JsonResponse {
        /** @var array{linkable_type: string, linkable_id: string, cursor?: string|null, limit?: int|string|null} $validated */
        $validated = $request->validate([
            'linkable_type' => ['required', 'string', Rule::in(QueryEntityTimelineAction::ALLOWED_LINKABLE_TYPES)],
            'linkable_id' => ['required', 'uuid'],
            'cursor' => ['nullable', 'string', 'max:512'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $modelClass = Relation::getMorphedModel($validated['linkable_type']);
        abort_if($modelClass === null || ! class_exists($modelClass), 404);

        Gate::forUser($request->user())->authorize('viewAny', $modelClass);

        $targetEntity = $modelClass::query()->findOrFail($validated['linkable_id']);
        Gate::forUser($request->user())->authorize('view', $targetEntity);

        /** @var User $viewer */
        $viewer = $request->user();

        $page = $queryTimeline->execute(
            viewer: $viewer,
            linkableType: $validated['linkable_type'],
            linkableId: $validated['linkable_id'],
            cursor: $validated['cursor'] ?? null,
            limit: isset($validated['limit']) ? (int) $validated['limit'] : 25,
        );

        return response()->json($page->toArray(), 200);
    }
}
