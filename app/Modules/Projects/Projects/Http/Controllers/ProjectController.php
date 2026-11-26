<?php

namespace App\Modules\Projects\Projects\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Rbac\Data\GrantCollaboratorData;
use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\CustomFields\Enums\CustomFieldEntityTypeEnum;
use App\Modules\Crm\Shared\Actions\LoadEntityContextAction;
use App\Modules\Projects\Projects\Actions\BuildProjectDetailDataAction;
use App\Modules\Projects\Projects\Actions\CreateProjectAction;
use App\Modules\Projects\Projects\Actions\DeleteProjectAction;
use App\Modules\Projects\Projects\Actions\ListProjectsAction;
use App\Modules\Projects\Projects\Actions\LoadProjectWorkspaceAction;
use App\Modules\Projects\Projects\Actions\ManageProjectCollaboratorAction;
use App\Modules\Projects\Projects\Actions\ManageProjectStagesAction;
use App\Modules\Projects\Projects\Actions\UpdateProjectAction;
use App\Modules\Projects\Projects\Data\CreateProjectData;
use App\Modules\Projects\Projects\Data\ManageProjectStagesData;
use App\Modules\Projects\Projects\Data\ProjectFilterData;
use App\Modules\Projects\Projects\Data\ProjectStageData;
use App\Modules\Projects\Projects\Data\UpdateProjectData;
use App\Modules\Projects\Projects\Enums\ProjectHealthEnum;
use App\Modules\Projects\Projects\Enums\ProjectStatusEnum;
use App\Modules\Projects\Projects\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public function index(
        Request $request,
        ListProjectsAction $listProjects,
        LoadEntityContextAction $context,
    ): Response {
        Gate::forUser($request->user())->authorize('viewAny', Project::class);

        /** @var User $viewer */
        $viewer = $request->user();

        $filter = ProjectFilterData::validateAndCreate($request->query());
        $projects = $listProjects->execute($viewer, $filter)->withQueryString();
        $canCreate = Gate::forUser($viewer)->allows('create', Project::class);

        return Inertia::render('projects/pages/ProjectsIndexPage', [
            'projects' => $projects,
            'filters' => $filter->toArray(),
            'assignableUsers' => $context->assignableUsers($viewer),
            'companies' => $canCreate ? $context->visibleCompanySummaries($viewer) : [],
            'customFieldDefinitions' => $context->customFieldDefinitions(CustomFieldEntityTypeEnum::Project),
            'statuses' => array_map(static fn (ProjectStatusEnum $s): string => $s->value, ProjectStatusEnum::cases()),
            'healthOptions' => array_map(static fn (ProjectHealthEnum $h): string => $h->value, ProjectHealthEnum::cases()),
            'can' => [
                'create' => $canCreate,
            ],
        ]);
    }

    public function show(
        Project $project,
        Request $request,
        LoadProjectWorkspaceAction $loadWorkspace,
    ): Response {
        Gate::forUser($request->user())->authorize('view', $project);

        /** @var User $viewer */
        $viewer = $request->user();

        return Inertia::render('projects/pages/ProjectWorkspacePage', $loadWorkspace->execute($viewer, $project)->toArray());
    }

    public function store(
        Request $request,
        CreateProjectAction $createProject,
        BuildProjectDetailDataAction $buildDetail,
    ): JsonResponse {
        Gate::forUser($request->user())->authorize('create', Project::class);

        /** @var User $actor */
        $actor = $request->user();

        $data = CreateProjectData::validateAndCreate($request->all());
        $project = $createProject->execute($actor, $data);

        return response()->json($buildDetail->execute($actor, $project)->toArray(), 201);
    }

    public function update(
        Project $project,
        Request $request,
        UpdateProjectAction $updateProject,
        BuildProjectDetailDataAction $buildDetail,
    ): JsonResponse {
        Gate::forUser($request->user())->authorize('update', $project);

        /** @var User $actor */
        $actor = $request->user();

        $data = UpdateProjectData::validateAndCreate($request->all());
        $updated = $updateProject->execute($actor, $project, $data);

        return response()->json($buildDetail->execute($actor, $updated)->toArray(), 200);
    }

    public function destroy(
        Project $project,
        Request $request,
        DeleteProjectAction $deleteProject,
    ): JsonResponse {
        Gate::forUser($request->user())->authorize('delete', $project);

        /** @var User $actor */
        $actor = $request->user();

        $deleteProject->execute($actor, $project);

        return response()->json(['deleted' => true], 200);
    }

    public function manageStages(
        Project $project,
        Request $request,
        ManageProjectStagesAction $manageStages,
    ): JsonResponse {
        Gate::forUser($request->user())->authorize('manageStages', $project);

        /** @var User $actor */
        $actor = $request->user();

        $data = ManageProjectStagesData::validateAndCreate($request->all());
        $stages = $manageStages->execute($actor, $project, $data);

        return response()->json([
            'stages' => array_map(
                static fn (ProjectStageData $stage): array => $stage->toArray(),
                $stages,
            ),
        ], 200);
    }

    public function grantCollaborator(
        Project $project,
        Request $request,
        ManageProjectCollaboratorAction $manageCollaborator,
    ): JsonResponse {
        Gate::forUser($request->user())->authorize('manageCollaborators', $project);

        /** @var User $actor */
        $actor = $request->user();

        $data = GrantCollaboratorData::validateAndCreate($request->all());
        $collaborator = $manageCollaborator->grant($actor, $project, $data);

        return response()->json($collaborator->toArray(), 201);
    }

    public function revokeCollaborator(
        Project $project,
        string $user,
        Request $request,
        ManageProjectCollaboratorAction $manageCollaborator,
    ): JsonResponse {
        Gate::forUser($request->user())->authorize('manageCollaborators', $project);

        /** @var User $actor */
        $actor = $request->user();

        $manageCollaborator->revoke($actor, $project, $user);

        return response()->json(['revoked' => true], 200);
    }
}
