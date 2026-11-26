<?php

namespace App\Modules\Projects\Tasks\Actions;

use App\Modules\Core\Users\Enums\UserStatusEnum;
use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Notes\Actions\SanitizeTipTapAstAction;
use App\Modules\Crm\Timeline\Actions\RecordActivityAction;
use App\Modules\Crm\Timeline\Enums\ActivityTypeEnum;
use App\Modules\Projects\Tasks\Data\UpdateTaskData;
use App\Modules\Projects\Tasks\Enums\TaskStatusEnum;
use App\Modules\Projects\Tasks\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Optional;

class UpdateTaskAction
{
    public function __construct(
        protected SanitizeTipTapAstAction $sanitizer,
        protected BuildTaskDataAction $buildTaskData,
        protected RecordActivityAction $recordActivity,
        protected BroadcastTaskBoardChangedAction $broadcastBoardChanged,
    ) {}

    public function execute(User $actor, Task $task, UpdateTaskData $data): Task
    {
        Gate::forUser($actor)->authorize('update', $task);

        if (! $data->title instanceof Optional && trim($data->title) === '') {
            throw ValidationException::withMessages([
                'title' => ['Task title cannot be empty.'],
            ]);
        }

        $sanitizedDescription = null;
        $hasDescriptionUpdate = ! $data->description_json instanceof Optional;
        if ($hasDescriptionUpdate && is_array($data->description_json)) {
            try {
                $sanitizedDescription = $this->sanitizer->execute($data->description_json, requireNonEmpty: false)['body_json'];
            } catch (ValidationException $e) {
                $errors = $e->errors();

                throw ValidationException::withMessages([
                    'description_json' => $errors['body_json'] ?? ['Description must be a valid TipTap doc document.'],
                ]);
            }
        }

        return DB::transaction(function () use (
            $actor,
            $task,
            $data,
            $hasDescriptionUpdate,
            $sanitizedDescription,
        ): Task {
            $lockedTask = Task::query()
                ->whereKey($task->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($data->expected_version !== $lockedTask->version) {
                throw $this->buildTaskData->buildConflictException($actor, $lockedTask);
            }

            $attributes = [];
            $changedFields = [];

            if (! $data->title instanceof Optional) {
                $nextTitle = Str::limit(trim($data->title), 255, '');
                if ($nextTitle !== $lockedTask->title) {
                    $attributes['title'] = $nextTitle;
                    $changedFields[] = 'title';
                }
            }

            if ($hasDescriptionUpdate && $sanitizedDescription !== $lockedTask->description_json) {
                $attributes['description_json'] = $sanitizedDescription;
                $changedFields[] = 'description_json';
            }

            if (! $data->assignee_id instanceof Optional) {
                $nextAssigneeId = $data->assignee_id !== null && trim($data->assignee_id) !== ''
                    ? trim($data->assignee_id)
                    : null;

                $currentAssigneeId = $lockedTask->assignee_id !== null ? (string) $lockedTask->assignee_id : null;
                if ($nextAssigneeId !== $currentAssigneeId) {
                    if ($nextAssigneeId !== null) {
                        $activeAssigneeExists = User::query()
                            ->whereKey($nextAssigneeId)
                            ->where('status', UserStatusEnum::Active->value)
                            ->exists();

                        if (! $activeAssigneeExists) {
                            throw ValidationException::withMessages([
                                'assignee_id' => ['The selected assignee must be an active employee.'],
                            ]);
                        }
                    }

                    $attributes['assignee_id'] = $nextAssigneeId;
                    $changedFields[] = 'assignee_id';
                }
            }

            if (! $data->priority instanceof Optional && $data->priority !== $lockedTask->priority) {
                $attributes['priority'] = $data->priority;
                $changedFields[] = 'priority';
            }

            $newlyCompleted = false;
            if (! $data->status instanceof Optional && $data->status !== $lockedTask->status) {
                $wasDone = $lockedTask->status === TaskStatusEnum::Done;
                $isNowDone = $data->status === TaskStatusEnum::Done;

                $attributes['status'] = $data->status;
                $changedFields[] = 'status';

                if (! $wasDone && $isNowDone) {
                    $attributes['completed_at'] = $lockedTask->completed_at ?? now();
                    $newlyCompleted = true;
                } elseif ($wasDone && ! $isNowDone) {
                    $attributes['completed_at'] = null;
                }
            }

            if (! $data->due_at instanceof Optional) {
                $nextDueAt = $data->due_at !== null && trim($data->due_at) !== ''
                    ? CarbonImmutable::parse($data->due_at)
                    : null;

                $currentDueIso = $lockedTask->due_at?->toIso8601String();
                $nextDueIso = $nextDueAt?->toIso8601String();

                if ($nextDueIso !== $currentDueIso) {
                    $attributes['due_at'] = $nextDueAt;
                    $changedFields[] = 'due_at';
                }
            }

            if (! $data->estimated_minutes instanceof Optional && $data->estimated_minutes !== $lockedTask->estimated_minutes) {
                $attributes['estimated_minutes'] = $data->estimated_minutes;
                $changedFields[] = 'estimated_minutes';
            }

            if ($attributes !== []) {
                $attributes['version'] = $lockedTask->version + 1;
                $lockedTask->update($attributes);

                $targets = ['task' => (string) $lockedTask->id];
                if ($lockedTask->project_id !== null) {
                    $targets['project'] = (string) $lockedTask->project_id;
                }
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
                            'project_id' => $lockedTask->project_id !== null ? (string) $lockedTask->project_id : null,
                        ],
                    );
                }

                $otherChangedFields = array_values(array_diff($changedFields, ['status']));
                if ($otherChangedFields !== [] || (! $newlyCompleted && in_array('status', $changedFields, true))) {
                    $this->recordActivity->execute(
                        actor: $actor,
                        activityType: ActivityTypeEnum::FieldUpdated,
                        sourceType: $lockedTask->getMorphClass(),
                        sourceId: (string) $lockedTask->id,
                        targets: $targets,
                        summary: 'Updated task fields: '.implode(', ', $changedFields),
                        metadata: ['changed_fields' => $changedFields],
                    );
                }

                if ($lockedTask->project_id !== null) {
                    $this->broadcastBoardChanged->afterCommit($lockedTask);
                }
            }

            return $lockedTask->refresh();
        });
    }
}
