<?php

namespace App\Modules\Projects\Tasks\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ToggleTaskWatcherData extends Data
{
    public function __construct(
        public ?string $user_id = null,
        public ?bool $watching = null,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'user_id' => ['nullable', 'uuid'],
            'watching' => ['nullable', 'boolean'],
        ];
    }
}
