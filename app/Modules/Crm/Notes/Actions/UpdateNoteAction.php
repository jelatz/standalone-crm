<?php

namespace App\Modules\Crm\Notes\Actions;

use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Notes\Data\UpdateNoteData;
use App\Modules\Crm\Notes\Models\Note;
use App\Modules\Crm\Timeline\Models\Activity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class UpdateNoteAction
{
    public function __construct(
        protected SanitizeTipTapAstAction $sanitizer,
    ) {}

    public function execute(User $actor, Note $note, UpdateNoteData $data): Note
    {
        Gate::forUser($actor)->authorize('update', $note);

        $sanitized = $this->sanitizer->execute($data->body_json, requireNonEmpty: true);

        return DB::transaction(function () use ($note, $sanitized): Note {
            $lockedNote = Note::query()
                ->whereKey($note->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedNote->update([
                'body_json' => $sanitized['body_json'],
                'body_text' => $sanitized['body_text'],
            ]);

            Activity::query()
                ->where('source_type', $lockedNote->getMorphClass())
                ->where('source_id', (string) $lockedNote->id)
                ->update([
                    'summary' => Str::limit($sanitized['body_text'], 255, ''),
                ]);

            return $lockedNote->refresh()->load('author');
        });
    }
}
