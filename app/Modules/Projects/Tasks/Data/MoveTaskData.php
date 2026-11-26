<?php

namespace App\Modules\Projects\Tasks\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class MoveTaskData extends Data
{
    public function __construct(
        public string $target_stage_id,
        public int $expected_version,
        public ?string $before_task_id = null,
        public ?string $after_task_id = null,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'target_stage_id' => ['required', 'uuid'],
            'expected_version' => ['required', 'integer', 'min:1'],
            'before_task_id' => ['nullable', 'uuid'],
            'after_task_id' => ['nullable', 'uuid'],
        ];
    }
}
