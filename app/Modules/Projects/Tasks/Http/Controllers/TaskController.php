<?php

namespace App\Modules\Projects\Tasks\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Users\Models\User;
use App\Modules\Projects\Tasks\Actions\BuildTaskDataAction;
use App\Modules\Projects\Tasks\Actions\CreateTaskAction;
use App\Modules\Projects\Tasks\Actions\DeleteTaskAction;
use App\Modules\Projects\Tasks\Actions\ListMyWorkAction;
use App\Modules\Projects\Tasks\Actions\MoveTaskAction;
use App\Modules\Projects\Tasks\Actions\ToggleTaskWatcherAction;
use App\Modules\Projects\Tasks\Actions\UpdateTaskAction;
use App\Modules\Projects\Tasks\Data\CreateTaskData;
use App\Modules\Projects\Tasks\Data\MoveTaskData;
use App\Modules\Projects\Tasks\Data\ToggleTaskWatcherData;
use App\Modules\Projects\Tasks\Data\UpdateTaskData;
use App\Modules\Projects\Tasks\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class TaskController extends Controller
{
    public function myWork(
        Request $request,
        ListMyWorkAction $listMyWork,
    ): Response {
        Gate::forUser($request->user())->authorize('viewAny', Task::class);

        /** @var User $viewer */
        $viewer = $request->user();

        return Inertia::render('projects/pages/MyWorkPage', $listMyWork->execute($viewer));
    }

    public function store(
        Request $request,
        CreateTaskAction $createTask,
        BuildTaskDataAction $buildTaskData,
    ): JsonResponse {
        Gate::forUser($request->user())->authorize('create', Task::class);

        /** @var User $actor */
        $actor = $request->user();

        $data = CreateTaskData::validateAndCreate($request->all());
        $task = $createTask->execute($actor, $data);

        return response()->json($buildTaskData->execute($actor, $task)->toArray(), 201);
    }

    public function update(
        Task $task,
        Request $request,
        UpdateTaskAction $updateTask,
        BuildTaskDataAction $buildTaskData,
    ): JsonResponse {
        Gate::forUser($request->user())->authorize('update', $task);

        /** @var User $actor */
        $actor = $request->user();

        $data = UpdateTaskData::validateAndCreate($request->all());
        $updated = $updateTask->execute($actor, $task, $data);

        return response()->json($buildTaskData->execute($actor, $updated)->toArray(), 200);
    }

    public function move(
        Task $task,
        Request $request,
        MoveTaskAction $moveTask,
        BuildTaskDataAction $buildTaskData,
    ): JsonResponse {
        Gate::forUser($request->user())->authorize('move', $task);

        /** @var User $actor */
        $actor = $request->user();

        $data = MoveTaskData::validateAndCreate($request->all());
        $moved = $moveTask->execute($actor, $task, $data);

        return response()->json($buildTaskData->execute($actor, $moved)->toArray(), 200);
    }

    public function destroy(
        Task $task,
        Request $request,
        DeleteTaskAction $deleteTask,
    ): JsonResponse {
        Gate::forUser($request->user())->authorize('delete', $task);

        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'expected_version' => ['nullable', 'integer', 'min:1'],
        ]);

        $expectedVersion = isset($validated['expected_version'])
            ? (int) $validated['expected_version']
            : null;

        $deleteTask->execute($actor, $task, $expectedVersion);

        return response()->json(['deleted' => true], 200);
    }

    public function toggleWatcher(
        Task $task,
        Request $request,
        ToggleTaskWatcherAction $toggleWatcher,
        BuildTaskDataAction $buildTaskData,
    ): JsonResponse {
        Gate::forUser($request->user())->authorize('toggleWatcher', $task);

        /** @var User $actor */
        $actor = $request->user();

        $data = ToggleTaskWatcherData::validateAndCreate($request->all());
        $updated = $toggleWatcher->execute($actor, $task, $data);

        return response()->json($buildTaskData->execute($actor, $updated)->toArray(), 200);
    }
}
