<?php

namespace App\Modules\Crm\Pipelines\Data;

use App\Modules\Crm\Pipelines\Models\Pipeline;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;
use Spatie\LaravelData\Support\Validation\ValidationContext;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class UpsertPipelineData extends Data
{
    public function __construct(
        public string|Optional $name = new Optional,
        public string|Optional|null $slug = new Optional,
        public string|Optional|null $department_id = new Optional,
        public bool|Optional $is_default = new Optional,
        public bool|Optional $is_active = new Optional,
        public int|Optional $sort_order = new Optional,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(ValidationContext $context): array
    {
        $routeModel = request()->route('pipeline');
        $existing = $routeModel instanceof Pipeline ? $routeModel : null;
        $isCreate = $existing === null;

        return [
            'name' => [$isCreate ? 'required' : 'sometimes', 'string', 'min:1', 'max:120'],
            'slug' => [
                'sometimes',
                'nullable',
                'string',
                'max:120',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('pipelines', 'slug')->ignore($existing?->id),
            ],
            'department_id' => ['sometimes', 'nullable', 'uuid', 'exists:departments,id'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
        ];
    }
}
