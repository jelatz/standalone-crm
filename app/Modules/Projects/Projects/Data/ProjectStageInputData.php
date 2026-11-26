<?php

namespace App\Modules\Projects\Projects\Data;

use App\Modules\Projects\Projects\Enums\ProjectStageCategoryEnum;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ProjectStageInputData extends Data
{
    public function __construct(
        public string $name,
        public ProjectStageCategoryEnum $category,
        public ?string $id = null,
        public ?int $wip_limit = null,
        public ?int $sort_order = null,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'id' => ['nullable', 'uuid'],
            'name' => ['required', 'string', 'min:1', 'max:80'],
            'category' => ['required', Rule::enum(ProjectStageCategoryEnum::class)],
            'wip_limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:10000'],
        ];
    }
}
