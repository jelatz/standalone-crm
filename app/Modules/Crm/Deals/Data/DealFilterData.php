<?php

namespace App\Modules\Crm\Deals\Data;

use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class DealFilterData extends Data
{
    /**
     * @var list<string>
     */
    public const array ALLOWED_SORT_FIELDS = [
        'board_position',
        'title',
        'amount_cents',
        'expected_close_date',
        'stage_entered_at',
        'updated_at',
        'created_at',
    ];

    /**
     * @param  array<string, mixed>|null  $custom_fields
     */
    public function __construct(
        public ?string $view = 'kanban',
        public ?string $pipeline_id = null,
        public ?string $stage_id = null,
        public ?string $owner_id = null,
        public ?string $company_id = null,
        public ?string $search = null,
        public ?array $custom_fields = null,
        public ?string $sort_by = 'board_position',
        public ?string $sort_dir = 'asc',
        public ?int $page = 1,
        public ?int $per_page = 25,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'view' => ['nullable', 'string', Rule::in(['kanban', 'table'])],
            'pipeline_id' => ['nullable', 'uuid'],
            'stage_id' => ['nullable', 'uuid'],
            'owner_id' => ['nullable', 'uuid'],
            'company_id' => ['nullable', 'uuid'],
            'search' => ['nullable', 'string', 'max:200'],
            'custom_fields' => ['nullable', 'array'],
            'sort_by' => ['nullable', 'string', Rule::in(self::ALLOWED_SORT_FIELDS)],
            'sort_dir' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
