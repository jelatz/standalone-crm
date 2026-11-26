<?php

namespace App\Modules\Projects\Projects\Data;

use App\Modules\Projects\Projects\Enums\ProjectHealthEnum;
use App\Modules\Projects\Projects\Enums\ProjectStatusEnum;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ProjectFilterData extends Data
{
    /**
     * @var list<string>
     */
    public const array ALLOWED_SORT_FIELDS = [
        'name',
        'code',
        'status',
        'health',
        'start_date',
        'target_end_date',
        'created_at',
        'updated_at',
    ];

    public function __construct(
        public ?ProjectStatusEnum $status = null,
        public ?ProjectHealthEnum $health = null,
        public ?string $owner_id = null,
        public ?string $company_id = null,
        public ?string $search = null,
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
            'status' => ['nullable', Rule::enum(ProjectStatusEnum::class)],
            'health' => ['nullable', Rule::enum(ProjectHealthEnum::class)],
            'owner_id' => ['nullable', 'uuid'],
            'company_id' => ['nullable', 'uuid'],
            'search' => ['nullable', 'string', 'max:200'],
            'sort_by' => ['nullable', 'string', Rule::in(self::ALLOWED_SORT_FIELDS)],
            'sort_dir' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
