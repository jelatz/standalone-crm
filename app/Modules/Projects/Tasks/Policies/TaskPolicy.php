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

        if ($target instanceof Task) {
            if ($target->parent_task_id !== null && $this->resolveParentTask($target) === null) {
                return false;
            }

            if ($target->project_id !== null && $this->resolveProject($target) === null) {
                return false;
            }
        }

        return Gate::forUser($user)->allows('update', $target);
    }

    public function view(User $user, Task $task): bool
    {
        return $this->authorizeView($user, $task);
    }

    public function update(User $user, Task $task): bool
    {
        return $this->authorizeTaskMutation($user, $task, PermissionEnum::TasksUpdate, allowAssignee: true);
    }

    public function move(User $user, Task $task): bool
    {
        return $this->authorizeTaskMutation($user, $task, PermissionEnum::TasksUpdate, allowAssignee: true);
    }

    public function delete(User $user, Task $task): bool
    {
        return $this->authorizeTaskMutation($user, $task, PermissionEnum::TasksDelete, allowAssignee: false);
    }

    public function toggleWatcher(User $user, Task $task): bool
    {
        return $this->view($user, $task);
    }

    /**
     * @param  list<string>  $visitedTaskIds
     */
    protected function authorizeView(User $user, Task $task, array $visitedTaskIds = []): bool
    {
        $taskId = (string) $task->getKey();

        if (
            ! $task->exists
            || $task->trashed()
            || $taskId === ''
            || in_array($taskId, $visitedTaskIds, true)
            || ! $this->isActiveEmployee($user)
            || ! $user->checkPermissionTo(PermissionEnum::TasksView->value)
        ) {
            return false;
        }

        $parent = null;
        if ($task->parent_task_id !== null) {
            $parent = $this->resolveParentTask($task);

            if ($parent === null) {
                return false;
            }
        }

        if (app(VisibleScopeResolver::class)->for($user)->isUnrestrictedAdmin) {
            return true;
        }

        if ($task->project_id !== null) {
            $project = $this->resolveProject($task);

            return $project !== null && Gate::forUser($user)->allows('view', $project);
        }

        if ($this->isCreatorOrAssignee($user, $task, includeAssignee: true) || $this->isWatcher($user, $task)) {
            return true;
        }

        $linkable = $this->resolveLinkable($task);

        if ($linkable !== null && Gate::forUser($user)->allows('view', $linkable)) {
            return true;
        }

        if ($parent !== null) {
            return $this->authorizeView($user, $parent, [...$visitedTaskIds, $taskId]);
        }

        return false;
    }

    protected function authorizeTaskMutation(
        User $user,
        Task $task,
        PermissionEnum $permission,
        bool $allowAssignee,
    ): bool {
        if (
            ! $task->exists
            || $task->trashed()
            || ! $this->isActiveEmployee($user)
            || ! $user->checkPermissionTo($permission->value)
        ) {
            return false;
        }

        if ($task->parent_task_id !== null && $this->resolveParentTask($task) === null) {
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

            if ($this->isCreatorOrAssignee($user, $task, includeAssignee: $allowAssignee)) {
                return true;
            }

            return Gate::forUser($user)->allows('update', $project);
        }

        if ($this->isCreatorOrAssignee($user, $task, includeAssignee: $allowAssignee)) {
            return true;
        }

        $linkable = $this->resolveLinkable($task);

        return $linkable !== null && Gate::forUser($user)->allows('update', $linkable);
    }

    protected function isCreatorOrAssignee(User $user, Task $task, bool $includeAssignee): bool
    {
        if ((string) $task->creator_id === (string) $user->id) {
            return true;
        }

        return $includeAssignee
            && $task->assignee_id !== null
            && (string) $task->assignee_id === (string) $user->id;
    }

    protected function isWatcher(User $user, Task $task): bool
    {
        return $task->watchers()->where('users.id', $user->id)->exists();
    }

    protected function resolveParentTask(Task $task): ?Task
    {
        if ($task->parent_task_id === null) {
            return null;
        }

        if ($task->relationLoaded('parent')) {
            $parent = $task->getRelation('parent');

            if ($parent instanceof Task && $parent->trashed()) {
                return null;
            }

            return $parent instanceof Task ? $parent : null;
        }

        $parent = Task::withTrashed()->find($task->parent_task_id);

        if ($parent === null || $parent->trashed()) {
            return null;
        }

        return $parent;
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
