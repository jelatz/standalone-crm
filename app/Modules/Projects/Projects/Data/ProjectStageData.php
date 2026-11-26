<?php

namespace App\Modules\Projects\Projects\Data;

use App\Modules\Projects\Projects\Enums\ProjectStageCategoryEnum;
use App\Modules\Projects\Projects\Models\ProjectStage;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ProjectStageData extends Data
{
    public function __construct(
        public string $id,
        public string $project_id,
        public string $name,
        public ProjectStageCategoryEnum $category,
        public int $sort_order,
        public ?int $wip_limit,
        public int $tasks_count = 0,
    ) {}

    public static function fromModel(ProjectStage $stage, ?int $tasksCount = null): self
    {
        return new self(
            id: (string) $stage->id,
            project_id: (string) $stage->project_id,
            name: $stage->name,
            category: $stage->category,
            sort_order: (int) $stage->sort_order,
            wip_limit: $stage->wip_limit !== null ? (int) $stage->wip_limit : null,
            tasks_count: $tasksCount ?? (int) ($stage->tasks_count ?? 0),
        );
    }
}
