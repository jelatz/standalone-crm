<?php

namespace App\Modules\Projects\Tasks\Actions;

use App\Modules\Core\Users\Enums\UserStatusEnum;
use App\Modules\Core\Users\Models\User;
use App\Modules\Projects\Tasks\Data\ToggleTaskWatcherData;
use App\Modules\Projects\Tasks\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ToggleTaskWatcherAction
{
    public function execute(User $actor, Task $task, ToggleTaskWatcherData $data): Task
    {
        Gate::forUser($actor)->authorize('toggleWatcher', $task);

        $targetUserId = $data->user_id !== null && trim($data->user_id) !== ''
            ? trim($data->user_id)
            : (string) $actor->id;

        if ($targetUserId !== (string) $actor->id) {
            Gate::forUser($actor)->authorize('update', $task);
        }

        $targetUser = User::query()
            ->whereKey($targetUserId)
            ->where('status', UserStatusEnum::Active->value)
            ->first();

        if ($targetUser === null) {
            throw ValidationException::withMessages([
                'user_id' => ['Task watcher must be an active employee.'],
            ]);
        }

        return DB::transaction(static function () use ($task, $targetUser, $data): Task {
            $lockedTask = Task::query()
                ->whereKey($task->id)
                ->lockForUpdate()
                ->firstOrFail();

            $currentlyWatching = DB::table('task_watchers')
                ->where('task_id', $lockedTask->id)
                ->where('user_id', $targetUser->id)
                ->exists();

            $shouldWatch = $data->watching !== null ? $data->watching : ! $currentlyWatching;

            if ($shouldWatch) {
                DB::table('task_watchers')->insertOrIgnore([
                    'task_id' => (string) $lockedTask->id,
                    'user_id' => (string) $targetUser->id,
                ]);
            } else {
                DB::table('task_watchers')
                    ->where('task_id', $lockedTask->id)
                    ->where('user_id', $targetUser->id)
                    ->delete();
            }

            return $lockedTask->refresh();
        });
    }
}
