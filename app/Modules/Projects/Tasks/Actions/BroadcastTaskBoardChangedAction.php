<?php

namespace App\Modules\Projects\Tasks\Actions;

use App\Modules\Projects\Tasks\Enums\TaskStatusEnum;
use App\Modules\Projects\Tasks\Events\TaskBoardChanged;
use App\Modules\Projects\Tasks\Models\Task;
use Illuminate\Support\Facades\DB;
use Throwable;

class BroadcastTaskBoardChangedAction
{
    /**
     * Register a DB::afterCommit callback that broadcasts ID-only TaskBoardChanged
     * on PresenceChannel("project-board.{projectId}") without ever failing the caller.
     */
    public function afterCommit(Task $task): void
    {
        if ($task->project_id === null) {
            return;
        }

        $projectId = (string) $task->project_id;
        $taskId = (string) $task->id;
        $projectStageId = $task->project_stage_id !== null ? (string) $task->project_stage_id : null;
        $boardPosition = (float) $task->board_position;
        $version = (int) $task->version;
        $status = $task->status instanceof TaskStatusEnum ? $task->status->value : (string) $task->status;

        DB::afterCommit(static function () use (
            $projectId,
            $taskId,
            $projectStageId,
            $boardPosition,
            $version,
            $status,
        ): void {
            try {
                event(new TaskBoardChanged(
                    projectId: $projectId,
                    taskId: $taskId,
                    projectStageId: $projectStageId,
                    boardPosition: $boardPosition,
                    version: $version,
                    status: $status,
                ));
            } catch (Throwable $e) {
                report($e);
            }
        });
    }
}
