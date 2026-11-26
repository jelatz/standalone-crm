<?php

namespace App\Modules\Projects\Tasks\Policies;

use App\Modules\Core\Rbac\Enums\PermissionEnum;
use App\Modules\Core\Users\Models\User;
use App\Modules\Projects\Projects\Models\Project;
use App\Modules\Projects\Tasks\Models\Task;
use App\Support\Silo\HandlesSiloPolicy;
use App\Support\Silo\VisibleScopeResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;

class TaskPolicy
{
    use HandlesSiloPolicy;

    public function viewAny(User $user): bool
    {
        return $this->isActiveEmployee($user)
            && $user->checkPermissionTo(PermissionEnum::TasksView->value);
    }

    public function create(User $user, ?Model $target = null): bool
    {
        if (! $this->isActiveEmployee($user) || ! $user->checkPermissionTo(PermissionEnum::TasksCreate->value)) {
            return false;
        }

        if ($target === null) {
            return true;
        }

        if (! $target->exists || (method_exists($target, 'trashed') && $target->trashed())) {
            return false;
        }

        return Gate::forUser($user)->allows('update', $target);
    }

    public function view(User $user, Task $task): bool
    {
        if (
            ! $task->exists
            || $task->trashed()
            || ! $this->isActiveEmployee($user)
            || ! $user->checkPermissionTo(PermissionEnum::TasksView->value)
        ) {
            return false;
        }

        if (app(VisibleScopeResolver::class)->for($user)->isUnrestrictedAdmin) {
            return true;
        }

        if ($task->project_id !== null) {
            $project = $this->resolveProject($task);

            return $project !== null && Gate::forUser($user)->allows('view', $project);
        }

        if ($this->isCreatorOrAssignee($user, $task) || $this->isWatcher($user, $task)) {
            return true;
        }

        $linkable = $this->resolveLinkable($task);

        return $linkable !== null && Gate::forUser($user)->allows('view', $linkable);
    }

    public function update(User $user, Task $task): bool
    {
        return $this->authorizeTaskMutation($user, $task, PermissionEnum::TasksUpdate);
    }

    public function move(User $user, Task $task): bool
    {
        return $this->authorizeTaskMutation($user, $task, PermissionEnum::TasksUpdate);
    }

    public function delete(User $user, Task $task): bool
    {
        return $this->authorizeTaskMutation($user, $task, PermissionEnum::TasksDelete);
    }

    public function toggleWatcher(User $user, Task $task): bool
    {
        return $this->view($user, $task);
    }

    protected function authorizeTaskMutation(User $user, Task $task, PermissionEnum $permission): bool
    {
        if (
            ! $task->exists
            || $task->trashed()
            || ! $this->isActiveEmployee($user)
            || ! $user->checkPermissionTo($permission->value)
        ) {
            return false;
        }

        if (app(VisibleScopeResolver::class)->for($user)->isUnrestrictedAdmin) {
            return true;
        }

        if ($task->project_id !== null) {
            $project = $this->resolveProject($task);

            if ($project === null || ! Gate::forUser($user)->allows('view', $project)) {
                return false;
            }

            if ($this->isCreatorOrAssignee($user, $task)) {
                return true;
            }

            return Gate::forUser($user)->allows('update', $project);
        }

        if ($this->isCreatorOrAssignee($user, $task)) {
            return true;
        }

        $linkable = $this->resolveLinkable($task);

        return $linkable !== null && Gate::forUser($user)->allows('update', $linkable);
    }

    protected function isCreatorOrAssignee(User $user, Task $task): bool
    {
        return (string) $task->creator_id === (string) $user->id
            || ($task->assignee_id !== null && (string) $task->assignee_id === (string) $user->id);
    }

    protected function isWatcher(User $user, Task $task): bool
    {
        return $task->watchers()->where('users.id', $user->id)->exists();
    }

    protected function resolveProject(Task $task): ?Project
    {
        if ($task->project_id === null) {
            return null;
        }

        if ($task->relationLoaded('project')) {
            $project = $task->getRelation('project');

            if ($project instanceof Project && $project->trashed()) {
                return null;
            }

            return $project instanceof Project ? $project : null;
        }

        return Project::query()->find($task->project_id);
    }

    protected function resolveLinkable(Task $task): ?Model
    {
        if ($task->linkable_type === null || $task->linkable_id === null) {
            return null;
        }

        $modelClass = Relation::getMorphedModel((string) $task->linkable_type);

        if ($modelClass === null || ! class_exists($modelClass)) {
            return null;
        }

        return $modelClass::query()->find($task->linkable_id);
    }
}
