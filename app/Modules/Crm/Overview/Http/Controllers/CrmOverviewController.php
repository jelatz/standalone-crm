<?php

namespace App\Modules\Crm\Overview\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Overview\Actions\LoadCrmOverviewAction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class CrmOverviewController extends Controller
{
    public function index(
        Request $request,
        LoadCrmOverviewAction $loadOverview,
    ): Response {
        /** @var User $viewer */
        $viewer = $request->user();

        Gate::forUser($viewer)->authorize('crm.overview.view');

        return Inertia::render('crm/pages/CrmOverviewPage', $loadOverview->execute($viewer));
    }
}
