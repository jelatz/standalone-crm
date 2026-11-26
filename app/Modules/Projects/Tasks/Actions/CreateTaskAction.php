<?php

namespace App\Modules\Projects\Tasks\Actions;

use App\Modules\Core\Users\Enums\UserStatusEnum;
use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Notes\Actions\SanitizeTipTapAstAction;
use App\Modules\Crm\Timeline\Actions\RecordActivityAction;
use App\Modules\Crm\Timeline\Enums\ActivityTypeEnum;
use App\Modules\Projects\Projects\Enums\ProjectStageCategoryEnum;
use App\Modules\Projects\Projects\Models\Project;
use App\Modules\Projects\Projects\Models\ProjectStage;
use App\Modules\Projects\Tasks\Data\CreateTaskData;
use App\Modules\Projects\Tasks\Enums\TaskStatusEnum;
use App\Modules\Projects\Tasks\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateTaskAction
{
    private const float POSITION_STEP = 65536.0;

    public function __construct(
        protected SanitizeTipTapAstAction $sanitizer,
        protected RecordActivityAction $recordActivity,
        protected BroadcastTaskBoardChangedAction $broadcastBoardChanged,
    ) {}

    public function execute(User $actor, CreateTaskData $data): Task
    {
        Gate::forUser($actor)->authorize('create', Task::class);

        $title = trim($data->title);
        if ($title === '') {
            throw ValidationException::withMessages([
                'title' => ['Task title is required.'],
            ]);
        }

        $requestedProjectId = $data->project_id !== null && trim($data->project_id) !== ''
            ? trim($data->project_id)
            : null;
        $requestedStageId = $data->project_stage_id !== null && trim($data->project_stage_id) !== ''
            ? trim($data->project_stage_id)
            : null;
        $parentTaskId = $data->parent_task_id !== null && trim($data->parent_task_id) !== ''
            ? trim($data->parent_task_id)
            : null;

        $linkableType = $data->linkable_type !== null && trim($data->linkable_type) !== ''
            ? trim($data->linkable_type)
            : null;
        $linkableId = $data->linkable_id !== null && trim($data->linkable_id) !== ''
            ? trim($data->linkable_id)
            : null;

        if (($linkableType === null) !== ($linkableId === null)) {
            throw ValidationException::withMessages([
                'linkable_type' => ['Both linkable_type and linkable_id are required when linking to a CRM record.'],
            ]);
        }

        $parentTask = null;
        if ($parentTaskId !== null) {
            $parentTask = Task::query()->find($parentTaskId);
            if ($parentTask === null || ! Gate::forUser($actor)->allows('create', [Task::class, $parentTask])) {
                throw new AuthorizationException('You are not authorized to create a subtask under the selected parent task.');
            }

            if ($parentTask->parent_task_id !== null) {
                throw ValidationException::withMessages([
                    'parent_task_id' => ['Subtasks cannot be nested more than one level deep.'],
                ]);
            }
        }

        $effectiveProjectId = $requestedProjectId ?? ($parentTask?->project_id !== null ? (string) $parentTask->project_id : null);

        $project = null;
        if ($effectiveProjectId !== null) {
            $project = Project::query()->find($effectiveProjectId);
            if ($project === null || ! Gate::forUser($actor)->allows('create', [Task::class, $project])) {
                throw new AuthorizationException('You are not authorized to create tasks in the selected project.');
            }
        }

        if ($parentTask !== null && (string) ($parentTask->project_id ?? '') !== (string) ($project?->id ?? '')) {
            throw ValidationException::withMessages([
                'parent_task_id' => ['Parent task must belong to the same project context.'],
            ]);
        }

        if ($project === null && $requestedStageId !== null) {
            throw ValidationException::withMessages([
                'project_stage_id' => ['Project stage requires a valid project_id.'],
            ]);
        }

        $linkable = null;
        if ($linkableType !== null && $linkableId !== null) {
            $linkable = $this->resolveAndAuthorizeLinkable($actor, $linkableType, $linkableId, requireUpdate: $project === null);
        }

        $assigneeId = null;
        if ($data->assignee_id !== null && trim($data->assignee_id) !== '') {
            $assigneeId = trim($data->assignee_id);
            $activeAssigneeExists = User::query()
                ->whereKey($assigneeId)
                ->where('status', UserStatusEnum::Active->value)
                ->exists();

            if (! $activeAssigneeExists) {
                throw ValidationException::withMessages([
                    'assignee_id' => ['The selected assignee must be an active employee.'],
                ]);
            }
        }

        $descriptionJson = $this->sanitizeDescription($data->description_json);
        $dueAt = $data->due_at !== null && trim($data->due_at) !== ''
            ? CarbonImmutable::parse($data->due_at)
            : null;

        return DB::transaction(function () use (
            $actor,
            $project,
            $requestedStageId,
            $parentTask,
            $linkableType,
            $linkable,
            $assigneeId,
            $title,
            $descriptionJson,
            $data,
            $dueAt,
        ): Task {
            $lockedStage = null;
            $boardPosition = self::POSITION_STEP;

            if ($project !== null) {
                $targetStageId = $requestedStageId ?? ($parentTask?->project_stage_id !== null ? (string) $parentTask->project_stage_id : null);

                if ($targetStageId !== null) {
                    $lockedStage = ProjectStage::query()
                        ->where('project_id', $project->id)
                        ->whereKey($targetStageId)
                        ->lockForUpdate()
                        ->first();

                    if ($lockedStage === null) {
                        throw ValidationException::withMessages([
                            'project_stage_id' => ['The selected stage does not belong to this project.'],
                        ]);
                    }
                } else {
                    $lockedStage = ProjectStage::query()
                        ->where('project_id', $project->id)
                        ->orderBy('sort_order')
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->first();

                    if ($lockedStage === null) {
                        throw ValidationException::withMessages([
                            'project_stage_id' => ['Project has no stages configured.'],
                        ]);
                    }
                }

                if ($lockedStage->wip_limit !== null) {
                    $stageTaskCount = Task::query()
                        ->where('project_id', $project->id)
                        ->where('project_stage_id', $lockedStage->id)
                        ->count();

                    if ($stageTaskCount >= $lockedStage->wip_limit) {
                        throw ValidationException::withMessages([
                            'project_stage_id' => ["Stage '{$lockedStage->name}' has reached its WIP limit of {$lockedStage->wip_limit}."],
                        ]);
                    }
                }

                $maxPosition = Task::query()
                    ->where('project_id', $project->id)
                    ->where('project_stage_id', $lockedStage->id)
                    ->max('board_position');
                $boardPosition = ($maxPosition !== null ? (float) $maxPosition : 0.0) + self::POSITION_STEP;
            }

            $status = $data->status ?? match ($lockedStage?->category) {
                ProjectStageCategoryEnum::Done => TaskStatusEnum::Done,
                ProjectStageCategoryEnum::Cancelled => TaskStatusEnum::Cancelled,
                default => TaskStatusEnum::Todo,
            };

            $completedAt = $status === TaskStatusEnum::Done ? now() : null;

            $task = Task::query()->create([
                'project_id' => $project?->id,
                'project_stage_id' => $lockedStage?->id,
                'parent_task_id' => $parentTask?->id,
                'linkable_type' => $linkable !== null ? $linkableType : null,
                'linkable_id' => $linkable !== null ? (string) $linkable->getKey() : null,
                'creator_id' => $actor->id,
                'assignee_id' => $assigneeId,
                'title' => Str::limit($title, 255, ''),
                'description_json' => $descriptionJson,
                'priority' => $data->priority,
                'status' => $status,
                'board_position' => $boardPosition,
                'due_at' => $dueAt,
                'completed_at' => $completedAt,
                'estimated_minutes' => $data->estimated_minutes,
                'version' => 1,
            ]);

            $targets = ['task' => (string) $task->id];
            if ($project !== null) {
                $targets['project'] = (string) $project->id;
            }
            if ($linkable !== null && $linkableType !== null) {
                $targets[$linkableType] = (string) $linkable->getKey();
            }

            $this->recordActivity->execute(
                actor: $actor,
                activityType: ActivityTypeEnum::TaskCreated,
                sourceType: $task->getMorphClass(),
                sourceId: (string) $task->id,
                targets: $targets,
                summary: "Created task: {$task->title}",
                metadata: [
                    'project_id' => $project !== null ? (string) $project->id : null,
                    'project_stage_id' => $lockedStage !== null ? (string) $lockedStage->id : null,
                    'parent_task_id' => $parentTask !== null ? (string) $parentTask->id : null,
                    'linkable_type' => $linkable !== null ? $linkableType : null,
                    'linkable_id' => $linkable !== null ? (string) $linkable->getKey() : null,
                ],
            );

            if ($task->project_id !== null) {
                $this->broadcastBoardChanged->afterCommit($task);
            }

            return $task->refresh();
        });
    }

    protected function resolveAndAuthorizeLinkable(
        User $actor,
        string $linkableType,
        string $linkableId,
        bool $requireUpdate,
    ): Model {
        if (! in_array($linkableType, ['company', 'contact', 'deal'], true)) {
            throw ValidationException::withMessages([
                'linkable_type' => ['Task linkable_type must be company, contact, or deal.'],
            ]);
        }

        $modelClass = Relation::getMorphedModel($linkableType);
        if ($modelClass === null || ! class_exists($modelClass)) {
            throw ValidationException::withMessages([
                'linkable_type' => ['Unsupported linkable entity type.'],
            ]);
        }

        $linkable = $modelClass::query()->find($linkableId);
        if ($linkable === null) {
            throw new AuthorizationException('You are not authorized to link a task to the selected CRM record.');
        }

        $allowed = $requireUpdate
            ? Gate::forUser($actor)->allows('create', [Task::class, $linkable])
            : Gate::forUser($actor)->allows('view', $linkable);

        if (! $allowed) {
            throw new AuthorizationException('You are not authorized to link a task to the selected CRM record.');
        }

        return $linkable;
    }

    /**
     * @param  array<mixed, mixed>|null  $raw
     * @return array<string, mixed>|null
     */
    protected function sanitizeDescription(?array $raw): ?array
    {
        if ($raw === null) {
            return null;
        }

        try {
            $sanitized = $this->sanitizer->execute($raw, requireNonEmpty: false);

            return $sanitized['body_json'];
        } catch (ValidationException $e) {
            $errors = $e->errors();

            throw ValidationException::withMessages([
                'description_json' => $errors['body_json'] ?? ['Description must be a valid TipTap doc document.'],
            ]);
        }
    }
}
