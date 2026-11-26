<?php

namespace App\Modules\Crm\Deals\Data;

use App\Modules\Crm\Deals\Enums\DealStakeholderRoleEnum;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class DealStakeholderInputData extends Data
{
    public function __construct(
        public string $contact_id,
        public DealStakeholderRoleEnum $stakeholder_role,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'contact_id' => ['required', 'uuid'],
            'stakeholder_role' => ['required', Rule::enum(DealStakeholderRoleEnum::class)],
        ];
    }
}
