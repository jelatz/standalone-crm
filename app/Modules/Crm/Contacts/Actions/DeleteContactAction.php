<?php

namespace App\Modules\Crm\Contacts\Actions;

use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Contacts\Models\Contact;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DeleteContactAction
{
    public function execute(User $actor, Contact $contact): void
    {
        Gate::forUser($actor)->authorize('delete', $contact);

        DB::transaction(static function () use ($contact): void {
            $contact->delete();
        });
    }
}
