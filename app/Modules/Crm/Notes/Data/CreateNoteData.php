<?php

namespace App\Modules\Crm\Notes\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\Validation\ArrayType;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class CreateNoteData extends Data
{
    /**
     * @param  array<string, mixed>  $body_json
     * @param  list<NoteTargetData>  $targets
     */
    public function __construct(
        #[Required, ArrayType]
        public array $body_json,
        #[Required, ArrayType, Min(1), DataCollectionOf(NoteTargetData::class)]
        public array $targets,
        public bool $is_pinned = false,
    ) {}
}
