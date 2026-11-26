<?php

namespace App\Modules\Crm\Notes\Data;

use App\Modules\Crm\Notes\Models\Note;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class NoteData extends Data
{
    /**
     * @param  array<string, mixed>  $body_json
     */
    public function __construct(
        public string $id,
        public string $author_id,
        public ?string $author_name,
        public array $body_json,
        public string $body_text,
        public bool $is_pinned,
        public string $created_at,
        public string $updated_at,
    ) {}

    public static function fromModel(Note $note): self
    {
        $authorName = $note->relationLoaded('author') ? $note->author?->name : null;

        return new self(
            id: (string) $note->id,
            author_id: (string) $note->author_id,
            author_name: $authorName,
            body_json: $note->body_json ?? [],
            body_text: $note->body_text,
            is_pinned: $note->is_pinned,
            created_at: $note->created_at?->toIso8601String() ?? now()->toIso8601String(),
            updated_at: $note->updated_at?->toIso8601String() ?? now()->toIso8601String(),
        );
    }
}
