<?php

namespace App\Modules\Crm\Pipelines\Data;

use App\Modules\Crm\Pipelines\Models\Pipeline;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class PipelineData extends Data
{
    /**
     * @param  list<PipelineStageData>  $stages
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public ?string $department_id,
        public bool $is_default,
        public bool $is_active,
        public int $sort_order,
        public array $stages,
    ) {}

    public static function fromModel(Pipeline $pipeline): self
    {
        $stages = $pipeline->relationLoaded('stages')
            ? $pipeline->stages
            : $pipeline->stages()->with('requiredCustomFields')->get();

        return new self(
            id: (string) $pipeline->id,
            name: $pipeline->name,
            slug: $pipeline->slug,
            department_id: $pipeline->department_id !== null ? (string) $pipeline->department_id : null,
            is_default: $pipeline->is_default,
            is_active: $pipeline->is_active,
            sort_order: $pipeline->sort_order,
            stages: $stages->map(static fn ($stage): PipelineStageData => PipelineStageData::fromModel($stage))->values()->all(),
        );
    }
}
