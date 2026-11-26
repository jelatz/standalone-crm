<?php

namespace App\Modules\Core\Sops\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class CreateSopData extends Data
{
    public function __construct(
        public string $module,
        public string $name,
        public ?string $content = '',
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'module' => ['required', 'string', 'max:80'],
            'name' => ['required', 'string', 'max:120'],
            'content' => ['nullable', 'string', 'max:524288'],
        ];
    }
}
