<?php

namespace App\Modules\Crm\Companies\Data;

use App\Modules\Crm\Companies\Enums\CompanyLifecycleStageEnum;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class CompanyFilterData extends Data
{
    /**
     * @var list<string>
     */
    public const array ALLOWED_SORT_FIELDS = [
        'name',
        'lifecycle_stage',
        'annual_revenue_cents',
        'updated_at',
        'created_at',
    ];

    /**
     * @param  array<string, mixed>|null  $custom_fields
     */
    public function __construct(
        public ?string $search = null,
        public ?CompanyLifecycleStageEnum $lifecycle_stage = null,
        public ?string $owner_id = null,
        public ?array $custom_fields = null,
        public ?string $sort_by = 'updated_at',
        public ?string $sort_dir = 'desc',
        public ?int $page = 1,
        public ?int $per_page = 25,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:200'],
            'lifecycle_stage' => ['nullable', Rule::enum(CompanyLifecycleStageEnum::class)],
            'owner_id' => ['nullable', 'uuid'],
            'custom_fields' => ['nullable', 'array'],
            'sort_by' => ['nullable', 'string', Rule::in(self::ALLOWED_SORT_FIELDS)],
            'sort_dir' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
