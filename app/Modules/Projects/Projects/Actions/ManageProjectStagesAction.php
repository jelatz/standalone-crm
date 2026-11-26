<?php

namespace App\Modules\Projects\Projects\Actions;

use App\Modules\Core\Users\Models\User;
use App\Modules\Projects\Projects\Data\ManageProjectStagesData;
use App\Modules\Projects\Projects\Data\ProjectStageData;
use App\Modules\Projects\Projects\Models\Project;
use App\Modules\Projects\Projects\Models\ProjectStage;
use App\Modules\Projects\Tasks\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ManageProjectStagesAction
{
    /**
     * Add, rename, reorder, set WIP limits, and delete empty stages for a project.
     *
     * @return list<ProjectStageData>
     */
    public function execute(User $actor, Project $project, ManageProjectStagesData $data): array
    {
        Gate::forUser($actor)->authorize('manageStages', $project);

        return DB::transaction(function () use ($project, $data): array {
            $lockedProject = Project::query()
                ->whereKey($project->id)
                ->lockForUpdate()
                ->firstOrFail();

            $existingStages = ProjectStage::query()
                ->where('project_id', $lockedProject->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy(static fn (ProjectStage $stage): string => (string) $stage->id);

            $submittedIds = [];
            foreach ($data->stages as $stageInput) {
                $name = trim($stageInput->name);
                if ($name === '') {
                    throw ValidationException::withMessages([
                        'stages' => ['Stage name cannot be empty.'],
                    ]);
                }

                if ($stageInput->id !== null && trim($stageInput->id) !== '') {
                    $stageId = trim($stageInput->id);
                    if (! $existingStages->has($stageId)) {
                        throw ValidationException::withMessages([
                            'stages' => ['One or more stage IDs do not belong to this project.'],
                        ]);
                    }

                    $submittedIds[] = $stageId;
                }
            }

            $deleteIds = $existingStages
                ->keys()
                ->reject(static fn (mixed $id): bool => in_array((string) $id, $submittedIds, true))
                ->map(static fn (mixed $id): string => (string) $id)
                ->values()
                ->all();

            if ($deleteIds !== []) {
                $hasTasks = Task::query()
                    ->where('project_id', $lockedProject->id)
                    ->whereIn('project_stage_id', $deleteIds)
                    ->exists();

                if ($hasTasks) {
                    throw ValidationException::withMessages([
                        'stages' => ['Cannot delete a project stage that still contains tasks.'],
                    ]);
                }

                ProjectStage::query()
                    ->where('project_id', $lockedProject->id)
                    ->whereIn('id', $deleteIds)
                    ->delete();
            }

            foreach (array_values($data->stages) as $index => $stageInput) {
                $sortOrder = $stageInput->sort_order ?? ($index + 1);
                $attributes = [
                    'name' => Str::limit(trim($stageInput->name), 80, ''),
                    'category' => $stageInput->category,
                    'sort_order' => $sortOrder,
                    'wip_limit' => $stageInput->wip_limit,
                ];

                if ($stageInput->id !== null && trim($stageInput->id) !== '') {
                    /** @var ProjectStage $stage */
                    $stage = $existingStages->get(trim($stageInput->id));
                    $stage->update($attributes);
                } else {
                    ProjectStage::query()->create([
                        'project_id' => $lockedProject->id,
                        ...$attributes,
                    ]);
                }
            }

            return ProjectStage::query()
                ->where('project_id', $lockedProject->id)
                ->withCount('tasks')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(static fn (ProjectStage $stage): ProjectStageData => ProjectStageData::fromModel($stage))
                ->values()
                ->all();
        });
    }
}
