<?php

namespace App\Modules\Crm\Companies\Actions;

use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Companies\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DeleteCompanyAction
{
    public function execute(User $actor, Company $company): void
    {
        Gate::forUser($actor)->authorize('delete', $company);

        DB::transaction(static function () use ($company): void {
            $company->delete();
        });
    }
}
