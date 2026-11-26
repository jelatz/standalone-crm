<?php

namespace App\Modules\Core\Sops\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class UpdateSopData extends Data
{
    public function __construct(
        public ?string $content,
        public string $expected_sha256,
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'content' => ['nullable', 'string', 'max:524288'],
            'expected_sha256' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
        ];
    }
}
