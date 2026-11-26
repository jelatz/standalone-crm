<?php

namespace App\Modules\Crm\Leads\Data;

use App\Modules\Crm\Leads\Enums\LeadSourceEnum;
use App\Modules\Crm\Leads\Enums\LeadStatusEnum;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class LeadFilterData extends Data
{
    /**
     * @var list<string>
     */
    public const array ALLOWED_SORT_FIELDS = [
        'last_name',
        'company_name',
        'status',
        'source',
        'estimated_value_cents',
        'updated_at',
        'created_at',
    ];

    /**
     * @param  array<string, mixed>|null  $custom_fields
     */
    public function __construct(
        public ?string $search = null,
        public ?LeadStatusEnum $status = null,
        public ?LeadSourceEnum $source = null,
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
            'status' => ['nullable', Rule::enum(LeadStatusEnum::class)],
            'source' => ['nullable', Rule::enum(LeadSourceEnum::class)],
            'owner_id' => ['nullable', 'uuid'],
            'custom_fields' => ['nullable', 'array'],
            'sort_by' => ['nullable', 'string', Rule::in(self::ALLOWED_SORT_FIELDS)],
            'sort_dir' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
