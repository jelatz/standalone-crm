<?php

namespace App\Modules\Crm\Notes\Actions;

use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Notes\Models\Note;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DeleteNoteAction
{
    public function execute(User $actor, Note $note): void
    {
        Gate::forUser($actor)->authorize('delete', $note);

        DB::transaction(function () use ($note): void {
            $note->delete();
        });
    }
}
