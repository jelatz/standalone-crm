<?php

namespace App\Modules\Crm\Notes\Data;

use Spatie\LaravelData\Attributes\Validation\ArrayType;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class UpdateNoteData extends Data
{
    /**
     * @param  array<string, mixed>  $body_json
     */
    public function __construct(
        #[Required, ArrayType]
        public array $body_json,
    ) {}
}
