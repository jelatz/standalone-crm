<?php

namespace App\Modules\Projects\Projects\Data;

use App\Modules\Projects\Projects\Enums\ProjectHealthEnum;
use App\Modules\Projects\Projects\Enums\ProjectStatusEnum;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class UpdateProjectData extends Data
{
    /**
     * @param  array<string, mixed>|Optional|null  $description_json
     * @param  array<string, mixed>|Optional  $custom_fields
     */
    public function __construct(
        public string|Optional $name = new Optional,
        public string|Optional|null $company_id = new Optional,
        public string|Optional|null $primary_deal_id = new Optional,
        public string|Optional $owner_id = new Optional,
        public string|Optional|null $account_manager_id = new Optional,
        public string|Optional $department_id = new Optional,
        public array|Optional|null $description_json = new Optional,
        public ProjectStatusEnum|Optional $status = new Optional,
        public ProjectHealthEnum|Optional $health = new Optional,
        public string|Optional|null $start_date = new Optional,
        public string|Optional|null $target_end_date = new Optional,
        public array|Optional $custom_fields = new Optional,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:1', 'max:200'],
            'company_id' => ['sometimes', 'nullable', 'uuid'],
            'primary_deal_id' => ['sometimes', 'nullable', 'uuid'],
            'owner_id' => ['sometimes', 'uuid'],
            'account_manager_id' => ['sometimes', 'nullable', 'uuid'],
            'department_id' => ['sometimes', 'uuid', Rule::exists('departments', 'id')],
            'description_json' => ['sometimes', 'nullable', 'array'],
            'status' => ['sometimes', Rule::enum(ProjectStatusEnum::class)],
            'health' => ['sometimes', Rule::enum(ProjectHealthEnum::class)],
            'start_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'target_end_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'custom_fields' => ['sometimes', 'array'],
        ];
    }
}
