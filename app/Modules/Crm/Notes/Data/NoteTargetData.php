<?php

namespace App\Modules\Crm\Notes\Data;

use Illuminate\Validation\Rule;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\Uuid;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class NoteTargetData extends Data
{
    public function __construct(
        #[Required]
        public string $linkable_type,
        #[Required, Uuid]
        public string $linkable_id,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'linkable_type' => ['required', 'string', Rule::in(['lead', 'company', 'contact', 'deal'])],
            'linkable_id' => ['required', 'uuid'],
        ];
    }
}
