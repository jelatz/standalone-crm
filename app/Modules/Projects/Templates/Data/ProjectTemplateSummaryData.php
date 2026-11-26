<?php

namespace App\Modules\Projects\Templates\Data;

use App\Modules\Projects\Templates\Models\ProjectTemplate;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ProjectTemplateSummaryData extends Data
{
    /**
     * @param  array<int, array<string, mixed>>  $default_stages
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $department_id,
        public ?string $department_name,
        public ?string $description,
        public bool $is_active,
        public array $default_stages,
        public int $tasks_count,
        public string $created_at,
        public string $updated_at,
    ) {}

    public static function fromModel(ProjectTemplate $template): self
    {
        $department = $template->relationLoaded('department') ? $template->department : null;
        $tasksCount = $template->getAttribute('tasks_count') !== null
            ? (int) $template->getAttribute('tasks_count')
            : ($template->relationLoaded('tasks') ? $template->tasks->count() : 0);

        return new self(
            id: (string) $template->id,
            name: $template->name,
            department_id: $template->department_id !== null ? (string) $template->department_id : null,
            department_name: $department?->name,
            description: $template->description,
            is_active: (bool) $template->is_active,
            default_stages: array_values($template->default_stages ?? []),
            tasks_count: $tasksCount,
            created_at: $template->created_at?->toIso8601String() ?? now()->toIso8601String(),
            updated_at: $template->updated_at?->toIso8601String() ?? now()->toIso8601String(),
        );
    }
}
