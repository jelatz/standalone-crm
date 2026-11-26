<?php

namespace App\Modules\Crm\Notes\Actions;

use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Notes\Models\Note;
use App\Modules\Crm\Timeline\Models\Activity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class TogglePinNoteAction
{
    public function execute(User $actor, Note $note, ?bool $pinned = null): Note
    {
        Gate::forUser($actor)->authorize('update', $note);

        $nextPinned = $pinned ?? ! $note->is_pinned;

        return DB::transaction(function () use ($note, $nextPinned): Note {
            $note->update(['is_pinned' => $nextPinned]);

            Activity::query()
                ->where('source_type', $note->getMorphClass())
                ->where('source_id', (string) $note->id)
                ->get()
                ->each(static function (Activity $activity) use ($nextPinned): void {
                    $metadata = $activity->metadata ?? [];
                    $metadata['is_pinned'] = $nextPinned;
                    $activity->update(['metadata' => $metadata]);
                });

            return $note->refresh()->load('author');
        });
    }
}
