<?php

namespace App\Modules\Projects\Tasks\Data;

use App\Modules\Projects\Tasks\Enums\TaskPriorityEnum;
use App\Modules\Projects\Tasks\Enums\TaskStatusEnum;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class CreateTaskData extends Data
{
    /**
     * @param  array<string, mixed>|null  $description_json
     */
    public function __construct(
        public string $title,
        public ?string $project_id = null,
        public ?string $project_stage_id = null,
        public ?string $parent_task_id = null,
        public ?string $linkable_type = null,
        public ?string $linkable_id = null,
        public ?string $assignee_id = null,
        public ?array $description_json = null,
        public TaskPriorityEnum $priority = TaskPriorityEnum::Medium,
        public ?TaskStatusEnum $status = null,
        public ?string $due_at = null,
        public ?int $estimated_minutes = null,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:1', 'max:255'],
            'project_id' => ['nullable', 'uuid'],
            'project_stage_id' => ['nullable', 'uuid'],
            'parent_task_id' => ['nullable', 'uuid'],
            'linkable_type' => ['nullable', 'required_with:linkable_id', 'string', Rule::in(['company', 'contact', 'deal'])],
            'linkable_id' => ['nullable', 'required_with:linkable_type', 'uuid'],
            'assignee_id' => ['nullable', 'uuid'],
            'description_json' => ['nullable', 'array'],
            'priority' => ['sometimes', Rule::enum(TaskPriorityEnum::class)],
            'status' => ['nullable', Rule::enum(TaskStatusEnum::class)],
            'due_at' => ['nullable', 'date'],
            'estimated_minutes' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ];
    }
}
