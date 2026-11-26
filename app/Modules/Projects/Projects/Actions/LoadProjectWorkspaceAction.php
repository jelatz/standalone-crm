<?php

namespace App\Modules\Projects\Projects\Actions;

use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\CustomFields\Enums\CustomFieldEntityTypeEnum;
use App\Modules\Crm\Shared\Actions\LoadEntityContextAction;
use App\Modules\Crm\Timeline\Actions\QueryEntityTimelineAction;
use App\Modules\Projects\Projects\Data\ProjectStageData;
use App\Modules\Projects\Projects\Data\ProjectWorkspaceData;
use App\Modules\Projects\Projects\Enums\ProjectHealthEnum;
use App\Modules\Projects\Projects\Enums\ProjectStageCategoryEnum;
use App\Modules\Projects\Projects\Enums\ProjectStatusEnum;
use App\Modules\Projects\Projects\Models\Project;
use App\Modules\Projects\Projects\Models\ProjectStage;
use App\Modules\Projects\Tasks\Actions\BuildTaskDataAction;
use App\Modules\Projects\Tasks\Data\TaskData;
use App\Modules\Projects\Tasks\Data\TaskStageColumnData;
use App\Modules\Projects\Tasks\Enums\TaskPriorityEnum;
use App\Modules\Projects\Tasks\Enums\TaskStatusEnum;
use App\Modules\Projects\Tasks\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class LoadProjectWorkspaceAction
{
    public function __construct(
        protected BuildProjectDetailDataAction $buildProjectDetail,
        protected BuildTaskDataAction $buildTaskData,
        protected LoadEntityContextAction $entityContext,
        protected QueryEntityTimelineAction $queryTimeline,
    ) {}

    public function execute(User $viewer, Project $project): ProjectWorkspaceData
    {
        Gate::forUser($viewer)->authorize('view', $project);

        $projectDetail = $this->buildProjectDetail->execute($viewer, $project);

        $stages = ProjectStage::query()
            ->where('project_id', $project->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $taskModels = Task::query()
            ->where('project_id', $project->id)
            ->where(static function (Builder $query): void {
                $query->whereNull('parent_task_id')
                    ->orWhereHas('parent');
            })
            ->with([
                'project:id,code,name',
                'stage:id,name,category',
                'creator:id,name',
                'assignee:id,name',
                'watchers:id',
            ])
            ->withCount('subtasks')
            ->orderBy('board_position')
            ->orderBy('id')
            ->get();

        $taskDtos = $this->buildTaskData->mapCollection($viewer, $taskModels);
        $tasksByStage = $taskDtos->groupBy(static fn (TaskData $task): string => $task->project_stage_id ?? '__unassigned__');

        $stageDtos = [];
        $columns = [];

        foreach ($stages as $stage) {
            /** @var list<TaskData> $stageTasks */
            $stageTasks = $tasksByStage->get((string) $stage->id)?->values()->all() ?? [];
            $stageCount = count($stageTasks);
            $stageDto = ProjectStageData::fromModel($stage, $stageCount);

            $stageDtos[] = $stageDto;
            $columns[] = new TaskStageColumnData(
                stage: $stageDto,
                tasks_count: $stageCount,
                tasks: $stageTasks,
            );
        }

        $canUpdate = Gate::forUser($viewer)->allows('update', $project);
        $canManageCollaborators = Gate::forUser($viewer)->allows('manageCollaborators', $project);
        $collaboratorOptions = $this->entityContext->collaboratorOptions();

        return new ProjectWorkspaceData(
            project: $projectDetail,
            stages: $stageDtos,
            columns: $columns,
            tasks: $taskDtos->values()->all(),
            collaborators: $this->entityContext->collaborators($project),
            attachments: $this->entityContext->cleanAttachments($project),
            customFieldDefinitions: $this->entityContext->customFieldDefinitions(CustomFieldEntityTypeEnum::Project),
            timeline: $this->queryTimeline->execute($viewer, 'project', (string) $project->id, null, 25),
            assignableUsers: $this->entityContext->assignableUsers($viewer, includeAllActive: $canUpdate || $canManageCollaborators),
            collaboratorAccessLevels: $collaboratorOptions['accessLevels'],
            collaboratorRoleLabels: $collaboratorOptions['roleLabels'],
            statuses: array_map(static fn (ProjectStatusEnum $s): string => $s->value, ProjectStatusEnum::cases()),
            healthOptions: array_map(static fn (ProjectHealthEnum $h): string => $h->value, ProjectHealthEnum::cases()),
            stageCategories: array_map(static fn (ProjectStageCategoryEnum $c): string => $c->value, ProjectStageCategoryEnum::cases()),
            taskPriorities: array_map(static fn (TaskPriorityEnum $p): string => $p->value, TaskPriorityEnum::cases()),
            taskStatuses: array_map(static fn (TaskStatusEnum $s): string => $s->value, TaskStatusEnum::cases()),
            can: [
                'update' => $canUpdate,
                'delete' => Gate::forUser($viewer)->allows('delete', $project),
                'manageStages' => Gate::forUser($viewer)->allows('manageStages', $project),
                'manageCollaborators' => $canManageCollaborators,
                'createTask' => Gate::forUser($viewer)->allows('create', [Task::class, $project]),
            ],
        );
    }
}
