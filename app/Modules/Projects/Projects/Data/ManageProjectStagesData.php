<?php

namespace App\Modules\Projects\Projects\Data;

use App\Modules\Projects\Projects\Enums\ProjectStageCategoryEnum;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ManageProjectStagesData extends Data
{
    /**
     * @param  list<ProjectStageInputData>  $stages
     */
    public function __construct(
        #[DataCollectionOf(ProjectStageInputData::class)]
        public array $stages,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'stages' => ['required', 'array', 'min:1', 'max:50'],
            'stages.*.id' => ['nullable', 'uuid', 'distinct'],
            'stages.*.name' => ['required', 'string', 'min:1', 'max:80'],
            'stages.*.category' => ['required', Rule::enum(ProjectStageCategoryEnum::class)],
            'stages.*.wip_limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'stages.*.sort_order' => ['nullable', 'integer', 'min:0', 'max:10000'],
        ];
    }
}
