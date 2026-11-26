<?php

namespace App\Modules\Projects\Tasks\Data;

use App\Modules\Projects\Tasks\Enums\TaskPriorityEnum;
use App\Modules\Projects\Tasks\Enums\TaskStatusEnum;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class UpdateTaskData extends Data
{
    /**
     * @param  array<string, mixed>|Optional|null  $description_json
     */
    public function __construct(
        public int $expected_version,
        public string|Optional $title = new Optional,
        public array|Optional|null $description_json = new Optional,
        public string|Optional|null $assignee_id = new Optional,
        public TaskPriorityEnum|Optional $priority = new Optional,
        public TaskStatusEnum|Optional $status = new Optional,
        public string|Optional|null $due_at = new Optional,
        public int|Optional|null $estimated_minutes = new Optional,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'expected_version' => ['required', 'integer', 'min:1'],
            'title' => ['sometimes', 'string', 'min:1', 'max:255'],
            'description_json' => ['sometimes', 'nullable', 'array'],
            'assignee_id' => ['sometimes', 'nullable', 'uuid'],
            'priority' => ['sometimes', Rule::enum(TaskPriorityEnum::class)],
            'status' => ['sometimes', Rule::enum(TaskStatusEnum::class)],
            'due_at' => ['sometimes', 'nullable', 'date'],
            'estimated_minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
        ];
    }
}
