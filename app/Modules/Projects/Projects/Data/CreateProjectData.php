<?php

namespace App\Modules\Projects\Projects\Data;

use App\Modules\Projects\Projects\Enums\ProjectHealthEnum;
use App\Modules\Projects\Projects\Enums\ProjectStageCategoryEnum;
use App\Modules\Projects\Projects\Enums\ProjectStatusEnum;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class CreateProjectData extends Data
{
    /**
     * @param  array<string, mixed>|null  $description_json
     * @param  array<string, mixed>  $custom_fields
     * @param  list<ProjectStageInputData>|null  $stages
     */
    public function __construct(
        public string $name,
        public ?string $company_id = null,
        public ?string $primary_deal_id = null,
        public ?string $owner_id = null,
        public ?string $account_manager_id = null,
        public ?string $department_id = null,
        public ?array $description_json = null,
        public ProjectStatusEnum $status = ProjectStatusEnum::Planning,
        public ProjectHealthEnum $health = ProjectHealthEnum::OnTrack,
        public ?string $start_date = null,
        public ?string $target_end_date = null,
        public array $custom_fields = [],
        #[DataCollectionOf(ProjectStageInputData::class)]
        public ?array $stages = null,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:200'],
            'company_id' => ['nullable', 'uuid'],
            'primary_deal_id' => ['nullable', 'uuid'],
            'owner_id' => ['nullable', 'uuid'],
            'account_manager_id' => ['nullable', 'uuid'],
            'department_id' => ['nullable', 'uuid', Rule::exists('departments', 'id')],
            'description_json' => ['nullable', 'array'],
            'status' => ['sometimes', Rule::enum(ProjectStatusEnum::class)],
            'health' => ['sometimes', Rule::enum(ProjectHealthEnum::class)],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'target_end_date' => ['nullable', 'date_format:Y-m-d'],
            'custom_fields' => ['sometimes', 'array'],
            'stages' => ['sometimes', 'nullable', 'array', 'max:50'],
            'stages.*.name' => ['required', 'string', 'min:1', 'max:80'],
            'stages.*.category' => ['required', Rule::enum(ProjectStageCategoryEnum::class)],
            'stages.*.wip_limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'stages.*.sort_order' => ['nullable', 'integer', 'min:0', 'max:10000'],
        ];
    }
}
