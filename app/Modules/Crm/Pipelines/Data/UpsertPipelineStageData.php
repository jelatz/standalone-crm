<?php

namespace App\Modules\Crm\Pipelines\Data;

use App\Modules\Crm\Pipelines\Enums\PipelineStageTypeEnum;
use App\Modules\Crm\Pipelines\Models\Pipeline;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;
use Spatie\LaravelData\Support\Validation\ValidationContext;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class UpsertPipelineStageData extends Data
{
    /**
     * @var list<string>
     */
    public const array ALLOWED_REQUIRED_CORE_FIELDS = [
        'amount_cents',
        'expected_close_date',
        'primary_contact_id',
        'lost_reason',
    ];

    /**
     * @param  list<string>|Optional  $required_core_fields
     * @param  list<string>|Optional  $required_custom_field_ids
     */
    public function __construct(
        public string|Optional $name = new Optional,
        public PipelineStageTypeEnum|Optional $stage_type = new Optional,
        public int|Optional $win_probability = new Optional,
        public int|Optional|null $rotting_days = new Optional,
        public array|Optional $required_core_fields = new Optional,
        public array|Optional $required_custom_field_ids = new Optional,
        public int|Optional $sort_order = new Optional,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(ValidationContext $context): array
    {
        $isCreate = request()->route('stage') === null;
        $routePipeline = request()->route('pipeline');
        $pipelineId = $routePipeline instanceof Pipeline ? (string) $routePipeline->id : null;

        return [
            'name' => [$isCreate ? 'required' : 'sometimes', 'string', 'min:1', 'max:100'],
            'stage_type' => [$isCreate ? 'required' : 'sometimes', Rule::enum(PipelineStageTypeEnum::class)],
            'win_probability' => [$isCreate ? 'required' : 'sometimes', 'integer', 'min:0', 'max:100'],
            'rotting_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:3650'],
            'required_core_fields' => ['sometimes', 'array'],
            'required_core_fields.*' => ['string', Rule::in(self::ALLOWED_REQUIRED_CORE_FIELDS)],
            'required_custom_field_ids' => ['sometimes', 'array'],
            'required_custom_field_ids.*' => [
                'uuid',
                Rule::exists('custom_field_definitions', 'id')->where(function ($query) use ($pipelineId): void {
                    $query->where('entity_type', 'deal')
                        ->where('is_active', true);

                    if ($pipelineId !== null) {
                        $query->where(function ($sub) use ($pipelineId): void {
                            $sub->whereNull('pipeline_id')->orWhere('pipeline_id', $pipelineId);
                        });
                    }
                }),
            ],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
        ];
    }
}
