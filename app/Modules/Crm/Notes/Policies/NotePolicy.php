<?php

namespace App\Modules\Crm\Notes\Policies;

use App\Modules\Core\Rbac\Enums\RoleEnum;
use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Notes\Models\Note;
use App\Modules\Crm\Timeline\Models\Activity;
use App\Modules\Crm\Timeline\Models\ActivityLink;
use App\Support\Silo\HandlesSiloPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;

class NotePolicy
{
    use HandlesSiloPolicy;

    public function create(User $user, ?Model $target = null): bool
    {
        if (! $this->isActiveEmployee($user)) {
            return false;
        }

        if ($target !== null) {
            return Gate::forUser($user)->allows('update', $target);
        }

        return $user->roles()->exists();
    }

    public function view(User $user, Note $note): bool
    {
        return $this->authorizeNoteAccess($user, $note, 'view');
    }

    public function update(User $user, Note $note): bool
    {
        return $this->authorizeNoteAccess($user, $note, 'update');
    }

    public function delete(User $user, Note $note): bool
    {
        return $this->authorizeNoteAccess($user, $note, 'update');
    }

    protected function authorizeNoteAccess(User $user, Note $note, string $targetAbility): bool
    {
        if (! $this->isActiveEmployee($user) || $note->trashed()) {
            return false;
        }

        if ($user->hasRole(RoleEnum::Admin->value)) {
            return true;
        }

        if ((string) $note->author_id === (string) $user->id) {
            return true;
        }

        $linksByType = ActivityLink::query()
            ->whereIn(
                'activity_id',
                Activity::query()
                    ->select('id')
                    ->where('source_type', $note->getMorphClass())
                    ->where('source_id', (string) $note->id),
            )
            ->get(['linkable_type', 'linkable_id'])
            ->groupBy('linkable_type');

        foreach ($linksByType as $linkableType => $links) {
            $modelClass = Relation::getMorphedModel((string) $linkableType);

            if ($modelClass === null || ! class_exists($modelClass)) {
                continue;
            }

            /** @var list<string> $ids */
            $ids = $links
                ->pluck('linkable_id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->unique()
                ->values()
                ->all();

            $targets = $modelClass::query()->whereKey($ids)->get();

            foreach ($targets as $target) {
                if (Gate::forUser($user)->allows($targetAbility, $target)) {
                    return true;
                }
            }
        }

        return false;
    }
}
