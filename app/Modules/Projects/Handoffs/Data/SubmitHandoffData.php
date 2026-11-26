<?php

namespace App\Modules\Projects\Handoffs\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class SubmitHandoffData extends Data
{
    /**
     * @param  array<string, mixed>  $scope_notes_json
     * @param  array<int, array{label: string, checked: bool}>  $handoff_checklist
     */
    public function __construct(
        public array $scope_notes_json,
        public array $handoff_checklist = [],
        public ?string $target_kickoff_date = null,
        public ?string $assigned_am_id = null,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'scope_notes_json' => ['required', 'array'],
            'handoff_checklist' => ['sometimes', 'array', 'max:50'],
            'handoff_checklist.*.label' => ['required', 'string', 'min:1', 'max:255'],
            'handoff_checklist.*.checked' => ['required', 'boolean'],
            'target_kickoff_date' => ['nullable', 'date_format:Y-m-d'],
            'assigned_am_id' => ['nullable', 'uuid'],
        ];
    }
}
