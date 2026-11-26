<?php

namespace App\Modules\Projects\Handoffs\Data;

use App\Modules\Projects\Projects\Enums\ProjectHealthEnum;
use App\Modules\Projects\Projects\Enums\ProjectStatusEnum;
use App\Modules\Projects\Projects\Models\Project;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class HandoffProjectSummaryData extends Data
{
    public function __construct(
        public string $id,
        public string $code,
        public string $name,
        public ProjectStatusEnum $status,
        public ProjectHealthEnum $health,
    ) {}

    public static function fromModel(Project $project): self
    {
        return new self(
            id: (string) $project->id,
            code: $project->code,
            name: $project->name,
            status: $project->status,
            health: $project->health,
        );
    }
}
