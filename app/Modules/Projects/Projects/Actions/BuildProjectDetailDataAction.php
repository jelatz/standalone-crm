<?php

namespace App\Modules\Projects\Projects\Actions;

use App\Modules\Core\Users\Models\User;
use App\Modules\Projects\Projects\Data\ProjectDetailData;
use App\Modules\Projects\Projects\Models\Project;
use Illuminate\Support\Facades\Gate;

class BuildProjectDetailDataAction
{
    public function execute(User $viewer, Project $project): ProjectDetailData
    {
        $project->load([
            'owner:id,name',
            'accountManager:id,name',
            'department:id,name',
            'company:id,name,domain,owner_id,account_manager_id',
            'primaryDeal:id,title,owner_id,account_manager_id',
        ]);

        $canViewCompany = $project->company !== null
            && Gate::forUser($viewer)->allows('view', $project->company);

        $canViewDeal = $project->primaryDeal !== null
            && Gate::forUser($viewer)->allows('view', $project->primaryDeal);

        return ProjectDetailData::fromModel($project, $canViewCompany, $canViewDeal);
    }
}
