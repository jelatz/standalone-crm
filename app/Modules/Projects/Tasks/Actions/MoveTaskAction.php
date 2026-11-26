<?php

namespace App\Modules\Projects\Tasks\Actions;

use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Timeline\Actions\RecordActivityAction;
use App\Modules\Crm\Timeline\Enums\ActivityTypeEnum;
use App\Modules\Projects\Projects\Enums\ProjectStageCategoryEnum;
use App\Modules\Projects\Projects\Models\ProjectStage;
use App\Modules\Projects\Tasks\Data\MoveTaskData;
use App\Modules\Projects\Tasks\Enums\TaskStatusEnum;
use App\Modules\Projects\Tasks\Models\Task;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class MoveTaskAction
{
    private const float POSITION_STEP = 65536.0;

    private const float REBALANCE_THRESHOLD = 0.0001;

    public function __construct(
        protected BuildTaskDataAction $buildTaskData,
        protected RecordActivityAction $recordActivity,
        protected BroadcastTaskBoardChangedAction $broadcastBoardChanged,
    ) {}

    public function execute(User $actor, Task $task, MoveTaskData $data): Task
    {
        Gate::forUser($actor)->authorize('move', $task);

        if ($task->project_id === null) {
            throw ValidationException::withMessages([
                'target_stage_id' => ['Only project tasks can be moved across project stages.'],
            ]);
        }

        if ($data->before_task_id !== null && $data->before_task_id === (string) $task->id) {
            throw ValidationException::withMessages([
                'before_task_id' => ['A task cannot be positioned relative to itself.'],
            ]);
        }

        if ($data->after_task_id !== null && $data->after_task_id === (string) $task->id) {
            throw ValidationException::withMessages([
                'after_task_id' => ['A task cannot be positioned relative to itself.'],
            ]);
        }

        if (
            $data->before_task_id !== null
            && $data->after_task_id !== null
            && $data->before_task_id === $data->after_task_id
        ) {
            throw ValidationException::withMessages([
                'after_task_id' => ['Neighbor task IDs must be distinct.'],
            ]);
        }

        return DB::transaction(function () use ($actor, $task, $data): Task {
            // Lock target ProjectStage first, then Task row to preserve canonical stage -> task lock order.
            $targetStage = ProjectStage::query()
                ->where('project_id', $task->project_id)
                ->whereKey($data->target_stage_id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedTask = Task::query()
                ->whereKey($task->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((string) $lockedTask->project_id !== (string) $targetStage->project_id) {
                throw (new ModelNotFoundException)->setModel(Task::class, [$task->id]);
            }

            if ($data->expected_version !== $lockedTask->version) {
                throw $this->buildTaskData->buildConflictException($actor, $lockedTask);
            }

            $stageChanged = (string) $lockedTask->project_stage_id !== (string) $targetStage->id;

            if ($stageChanged && $targetStage->wip_limit !== null) {
                $stageCount = Task::query()
                    ->where('project_id', $lockedTask->project_id)
                    ->where('project_stage_id', $targetStage->id)
                    ->count();

                if ($stageCount >= $targetStage->wip_limit) {
                    throw ValidationException::withMessages([
                        'target_stage_id' => ["Stage '{$targetStage->name}' has reached its WIP limit of {$targetStage->wip_limit}."],
                    ]);
                }
            }

            $previousStage = $stageChanged && $lockedTask->project_stage_id !== null
                ? ProjectStage::query()->find($lockedTask->project_stage_id)
                : null;

            $boardPosition = $this->computeBoardPosition(
                lockedTask: $lockedTask,
                targetStage: $targetStage,
                beforeTaskId: $data->before_task_id,
                afterTaskId: $data->after_task_id,
            );

            $wasDone = $lockedTask->status === TaskStatusEnum::Done;
            $nextStatus = $lockedTask->status;
            $nextCompletedAt = $lockedTask->completed_at;
            $newlyCompleted = false;

            if ($targetStage->category === ProjectStageCategoryEnum::Done) {
                $nextStatus = TaskStatusEnum::Done;
                $nextCompletedAt = $lockedTask->completed_at ?? now();
                $newlyCompleted = ! $wasDone;
            } elseif ($targetStage->category === ProjectStageCategoryEnum::Cancelled) {
                $nextStatus = TaskStatusEnum::Cancelled;
                $nextCompletedAt = null;
            } elseif ($stageChanged) {
                if ($previousStage?->category === ProjectStageCategoryEnum::Done || $wasDone) {
                    $nextStatus = match ($targetStage->category) {
                        ProjectStageCategoryEnum::InProgress => TaskStatusEnum::InProgress,
                        ProjectStageCategoryEnum::Review => TaskStatusEnum::Review,
                        default => TaskStatusEnum::Todo,
                    };
                    $nextCompletedAt = null;
                } elseif ($lockedTask->status === TaskStatusEnum::Cancelled && $previousStage?->category === ProjectStageCategoryEnum::Cancelled) {
                    $nextStatus = match ($targetStage->category) {
                        ProjectStageCategoryEnum::InProgress => TaskStatusEnum::InProgress,
                        ProjectStageCategoryEnum::Review => TaskStatusEnum::Review,
                        default => TaskStatusEnum::Todo,
                    };
                    $nextCompletedAt = null;
                }
            }

            $lockedTask->update([
                'project_stage_id' => $targetStage->id,
                'board_position' => $boardPosition,
                'status' => $nextStatus,
                'completed_at' => $nextCompletedAt,
                'version' => $lockedTask->version + 1,
            ]);

            $targets = [
                'task' => (string) $lockedTask->id,
                'project' => (string) $lockedTask->project_id,
            ];
            if ($lockedTask->linkable_type !== null && $lockedTask->linkable_id !== null) {
                $targets[$lockedTask->linkable_type] = (string) $lockedTask->linkable_id;
            }

            if ($newlyCompleted) {
                $this->recordActivity->execute(
                    actor: $actor,
                    activityType: ActivityTypeEnum::TaskCompleted,
                    sourceType: $lockedTask->getMorphClass(),
                    sourceId: (string) $lockedTask->id,
                    targets: $targets,
                    summary: "Completed task: {$lockedTask->title}",
                    metadata: [
                        'project_id' => (string) $lockedTask->project_id,
                        'from_stage_id' => $previousStage !== null ? (string) $previousStage->id : null,
                        'to_stage_id' => (string) $targetStage->id,
                        'to_stage_name' => $targetStage->name,
                    ],
                );
            } elseif ($stageChanged) {
                $fromStageName = $previousStage?->name ?? 'Previous Stage';

                $this->recordActivity->execute(
                    actor: $actor,
                    activityType: ActivityTypeEnum::StageChanged,
                    sourceType: $lockedTask->getMorphClass(),
                    sourceId: (string) $lockedTask->id,
                    targets: $targets,
                    summary: "Moved task {$lockedTask->title} from {$fromStageName} to {$targetStage->name}",
                    metadata: [
                        'project_id' => (string) $lockedTask->project_id,
                        'from_stage_id' => $previousStage !== null ? (string) $previousStage->id : null,
                        'from_stage_name' => $fromStageName,
                        'to_stage_id' => (string) $targetStage->id,
                        'to_stage_name' => $targetStage->name,
                    ],
                );
            }

            $this->broadcastBoardChanged->afterCommit($lockedTask);

            return $lockedTask->refresh();
        });
    }

    protected function computeBoardPosition(
        Task $lockedTask,
        ProjectStage $targetStage,
        ?string $beforeTaskId,
        ?string $afterTaskId,
    ): float {
        $neighborIds = array_values(array_unique(array_filter(
            [$beforeTaskId, $afterTaskId],
            static fn (?string $id): bool => $id !== null && $id !== (string) $lockedTask->id,
        )));
        sort($neighborIds, SORT_STRING);

        /** @var array<string, Task> $lockedNeighbors */
        $lockedNeighbors = [];
        foreach ($neighborIds as $neighborId) {
            $lockedNeighbors[$neighborId] = Task::query()
                ->where('project_id', $lockedTask->project_id)
                ->where('project_stage_id', $targetStage->id)
                ->whereKey($neighborId)
                ->lockForUpdate()
                ->firstOrFail();
        }

        $beforeTask = $beforeTaskId !== null ? ($lockedNeighbors[$beforeTaskId] ?? null) : null;
        $afterTask = $afterTaskId !== null ? ($lockedNeighbors[$afterTaskId] ?? null) : null;

        if ($beforeTask === null && $afterTask === null) {
            $maxPosition = Task::query()
                ->where('project_id', $lockedTask->project_id)
                ->where('project_stage_id', $targetStage->id)
                ->whereKeyNot($lockedTask->id)
                ->max('board_position');

            return ($maxPosition !== null ? (float) $maxPosition : 0.0) + self::POSITION_STEP;
        }

        if ($beforeTask !== null && $afterTask !== null) {
            $beforePos = (float) $beforeTask->board_position;
            $afterPos = (float) $afterTask->board_position;

            if ($beforePos > $afterPos) {
                throw ValidationException::withMessages([
                    'before_task_id' => ['The preceding task appears after the following task in this stage.'],
                ]);
            }

            if (($afterPos - $beforePos) < self::REBALANCE_THRESHOLD) {
                $rebalanced = $this->rebalanceStage($lockedTask, $targetStage);
                $beforePos = $rebalanced[(string) $beforeTask->id];
                $afterPos = $rebalanced[(string) $afterTask->id];
            }

            return $beforePos + (($afterPos - $beforePos) / 2.0);
        }

        if ($afterTask !== null) {
            $afterPos = (float) $afterTask->board_position;
            $prevMax = Task::query()
                ->where('project_id', $lockedTask->project_id)
                ->where('project_stage_id', $targetStage->id)
                ->whereKeyNot($lockedTask->id)
                ->where('board_position', '<', $afterPos)
                ->max('board_position');

            $lowerBound = $prevMax !== null
                ? (float) $prevMax
                : ($afterPos > 0.0 ? 0.0 : $afterPos - self::POSITION_STEP);

            if (($afterPos - $lowerBound) < self::REBALANCE_THRESHOLD) {
                $rebalanced = $this->rebalanceStage($lockedTask, $targetStage);
                $afterPos = $rebalanced[(string) $afterTask->id];
                $orderedIds = array_keys($rebalanced);
                $afterIndex = array_search((string) $afterTask->id, $orderedIds, true);
                $lowerBound = ($afterIndex !== false && $afterIndex > 0)
                    ? $rebalanced[$orderedIds[$afterIndex - 1]]
                    : 0.0;
            }

            return $lowerBound + (($afterPos - $lowerBound) / 2.0);
        }

        // $beforeTask !== null && $afterTask === null
        $beforePos = (float) $beforeTask->board_position;
        $nextMin = Task::query()
            ->where('project_id', $lockedTask->project_id)
            ->where('project_stage_id', $targetStage->id)
            ->whereKeyNot($lockedTask->id)
            ->where('board_position', '>', $beforePos)
            ->min('board_position');

        if ($nextMin === null) {
            return $beforePos + self::POSITION_STEP;
        }

        $upperBound = (float) $nextMin;
        if (($upperBound - $beforePos) < self::REBALANCE_THRESHOLD) {
            $rebalanced = $this->rebalanceStage($lockedTask, $targetStage);
            $beforePos = $rebalanced[(string) $beforeTask->id];
            $orderedIds = array_keys($rebalanced);
            $beforeIndex = array_search((string) $beforeTask->id, $orderedIds, true);

            if ($beforeIndex === false || ! isset($orderedIds[$beforeIndex + 1])) {
                return $beforePos + self::POSITION_STEP;
            }

            $upperBound = $rebalanced[$orderedIds[$beforeIndex + 1]];
        }

        return $beforePos + (($upperBound - $beforePos) / 2.0);
    }

    /**
     * Rebalance all non-deleted tasks in the target stage (excluding the task being moved)
     * into uniform 65536 increments and return an ordered [task_id => board_position] map.
     *
     * @return array<string, float>
     */
    protected function rebalanceStage(Task $movingTask, ProjectStage $targetStage): array
    {
        $stageTasks = Task::query()
            ->where('project_id', $movingTask->project_id)
            ->where('project_stage_id', $targetStage->id)
            ->whereKeyNot($movingTask->id)
            ->orderBy('board_position')
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'board_position']);

        $positions = [];
        $step = 1;

        foreach ($stageTasks as $stageTask) {
            $nextPosition = $step * self::POSITION_STEP;
            Task::query()
                ->whereKey($stageTask->id)
                ->update(['board_position' => $nextPosition]);

            $positions[(string) $stageTask->id] = $nextPosition;
            $step++;
        }

        return $positions;
    }
}
