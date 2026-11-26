<?php

namespace App\Modules\Projects\Tasks\Actions;

use App\Modules\Core\Users\Models\User;
use App\Modules\Projects\Tasks\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DeleteTaskAction
{
    public function __construct(
        protected BuildTaskDataAction $buildTaskData,
        protected BroadcastTaskBoardChangedAction $broadcastBoardChanged,
    ) {}

    public function execute(User $actor, Task $task, ?int $expectedVersion = null): void
    {
        Gate::forUser($actor)->authorize('delete', $task);

        DB::transaction(function () use ($actor, $task, $expectedVersion): void {
            $lockedTask = Task::query()
                ->whereKey($task->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($expectedVersion !== null && $expectedVersion !== $lockedTask->version) {
                throw $this->buildTaskData->buildConflictException($actor, $lockedTask);
            }

            if ($lockedTask->project_id !== null) {
                $this->broadcastBoardChanged->afterCommit($lockedTask);
            }

            Task::query()
                ->where('parent_task_id', $lockedTask->id)
                ->delete();

            $lockedTask->delete();
        });
    }
}
