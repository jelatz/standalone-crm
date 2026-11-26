<?php

namespace App\Modules\Crm\Pipelines\Data;

use App\Modules\Crm\Pipelines\Enums\PipelineStageTypeEnum;
use App\Modules\Crm\Pipelines\Models\PipelineStage;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class PipelineStageData extends Data
{
    /**
     * @param  list<string>  $required_core_fields
     * @param  list<string>  $required_custom_field_ids
     */
    public function __construct(
        public string $id,
        public string $pipeline_id,
        public string $name,
        public PipelineStageTypeEnum $stage_type,
        public int $win_probability,
        public ?int $rotting_days,
        public array $required_core_fields,
        public array $required_custom_field_ids,
        public int $sort_order,
    ) {}

    public static function fromModel(PipelineStage $stage): self
    {
        $requiredFieldIds = $stage->relationLoaded('requiredCustomFields')
            ? $stage->requiredCustomFields->pluck('id')->map(static fn (mixed $id): string => (string) $id)->values()->all()
            : $stage->requiredCustomFields()->pluck('custom_field_definitions.id')->map(static fn (mixed $id): string => (string) $id)->values()->all();

        /** @var list<string> $coreFields */
        $coreFields = array_values(array_map('strval', $stage->required_core_fields ?? []));

        return new self(
            id: (string) $stage->id,
            pipeline_id: (string) $stage->pipeline_id,
            name: $stage->name,
            stage_type: $stage->stage_type,
            win_probability: $stage->win_probability,
            rotting_days: $stage->rotting_days,
            required_core_fields: $coreFields,
            required_custom_field_ids: $requiredFieldIds,
            sort_order: $stage->sort_order,
        );
    }
}
