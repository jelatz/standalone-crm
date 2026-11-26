<?php

namespace App\Modules\Projects\Tasks\Data;

use App\Modules\Core\Users\Models\User;
use App\Modules\Projects\Projects\Enums\ProjectStageCategoryEnum;
use App\Modules\Projects\Tasks\Enums\TaskPriorityEnum;
use App\Modules\Projects\Tasks\Enums\TaskStatusEnum;
use App\Modules\Projects\Tasks\Models\Task;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class TaskData extends Data
{
    /**
     * @param  array<string, mixed>|null  $description_json
     * @param  list<string>  $watcher_ids
     */
    public function __construct(
        public string $id,
        public ?string $project_id,
        public ?string $project_code,
        public ?string $project_name,
        public ?string $project_stage_id,
        public ?string $project_stage_name,
        public ?ProjectStageCategoryEnum $project_stage_category,
        public ?string $parent_task_id,
        public ?string $linkable_type,
        public ?string $linkable_id,
        public string $creator_id,
        public ?string $creator_name,
        public ?string $assignee_id,
        public ?string $assignee_name,
        public string $title,
        public ?array $description_json,
        public TaskPriorityEnum $priority,
        public TaskStatusEnum $status,
        public float $board_position,
        public ?string $due_at,
        public ?string $completed_at,
        public ?int $estimated_minutes,
        public int $version,
        public int $subtasks_count = 0,
        public int $watchers_count = 0,
        public array $watcher_ids = [],
        public bool $is_watching = false,
        public bool $has_masked_linkable = false,
        public string $created_at = '',
        public string $updated_at = '',
    ) {}

    public static function fromModel(
        Task $task,
        ?User $viewer = null,
        bool $canViewLinkable = true,
    ): self {
        $project = $task->relationLoaded('project') ? $task->project : null;
        $stage = $task->relationLoaded('stage') ? $task->stage : null;
        $creator = $task->relationLoaded('creator') ? $task->creator : null;
        $assignee = $task->relationLoaded('assignee') ? $task->assignee : null;

        /** @var list<string> $watcherIds */
        $watcherIds = $task->relationLoaded('watchers')
            ? $task->watchers->pluck('id')->map(static fn (mixed $id): string => (string) $id)->values()->all()
            : [];

        $hasMaskedLinkable = $task->linkable_type !== null
            && $task->linkable_id !== null
            && ! $canViewLinkable;

        $subtasksCount = $task->relationLoaded('subtasks')
            ? $task->subtasks->count()
            : (int) ($task->subtasks_count ?? 0);

        $watchersCount = $task->relationLoaded('watchers')
            ? count($watcherIds)
            : (int) ($task->watchers_count ?? 0);

        return new self(
            id: (string) $task->id,
            project_id: $task->project_id !== null ? (string) $task->project_id : null,
            project_code: $project?->code,
            project_name: $project?->name,
            project_stage_id: $task->project_stage_id !== null ? (string) $task->project_stage_id : null,
            project_stage_name: $stage?->name,
            project_stage_category: $stage?->category,
            parent_task_id: $task->parent_task_id !== null ? (string) $task->parent_task_id : null,
            linkable_type: $canViewLinkable ? $task->linkable_type : null,
            linkable_id: $canViewLinkable && $task->linkable_id !== null ? (string) $task->linkable_id : null,
            creator_id: (string) $task->creator_id,
            creator_name: $creator?->name,
            assignee_id: $task->assignee_id !== null ? (string) $task->assignee_id : null,
            assignee_name: $assignee?->name,
            title: $task->title,
            description_json: $task->description_json,
            priority: $task->priority,
            status: $task->status,
            board_position: (float) $task->board_position,
            due_at: $task->due_at?->toIso8601String(),
            completed_at: $task->completed_at?->toIso8601String(),
            estimated_minutes: $task->estimated_minutes !== null ? (int) $task->estimated_minutes : null,
            version: (int) $task->version,
            subtasks_count: $subtasksCount,
            watchers_count: $watchersCount,
            watcher_ids: $watcherIds,
            is_watching: $viewer !== null && in_array((string) $viewer->id, $watcherIds, true),
            has_masked_linkable: $hasMaskedLinkable,
            created_at: $task->created_at?->toIso8601String() ?? now()->toIso8601String(),
            updated_at: $task->updated_at?->toIso8601String() ?? now()->toIso8601String(),
        );
    }
}
