<?php

namespace App\Modules\Projects\Templates\Actions;

use App\Modules\Core\Users\Models\User;
use App\Modules\Projects\Templates\Data\ProjectTemplateSummaryData;
use App\Modules\Projects\Templates\Models\ProjectTemplate;
use Illuminate\Support\Facades\Gate;

class ListProjectTemplatesAction
{
    /**
     * @return list<ProjectTemplateSummaryData>
     */
    public function execute(User $viewer, bool $activeOnly = true): array
    {
        Gate::forUser($viewer)->authorize('viewAny', ProjectTemplate::class);

        return ProjectTemplate::query()
            ->with('department:id,name')
            ->withCount('tasks')
            ->when($activeOnly, static fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->get()
            ->map(static fn (ProjectTemplate $template): ProjectTemplateSummaryData => ProjectTemplateSummaryData::fromModel($template))
            ->values()
            ->all();
    }
}
