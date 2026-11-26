<?php

namespace App\Modules\Projects\Projects\Actions;

use App\Modules\Core\Users\Models\User;
use App\Modules\Projects\Projects\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DeleteProjectAction
{
    public function execute(User $actor, Project $project): void
    {
        Gate::forUser($actor)->authorize('delete', $project);

        DB::transaction(function () use ($project): void {
            $lockedProject = Project::query()
                ->whereKey($project->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedProject->delete();
        });
    }
}
