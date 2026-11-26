<?php

namespace App\Modules\Crm\Leads\Actions;

use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Leads\Models\Lead;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DeleteLeadAction
{
    public function execute(User $actor, Lead $lead): void
    {
        Gate::forUser($actor)->authorize('delete', $lead);

        DB::transaction(static function () use ($lead): void {
            $lead->delete();
        });
    }
}
