<?php

namespace App\Modules\Projects\Tasks\Actions;

use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Companies\Models\Company;
use App\Modules\Crm\Contacts\Models\Contact;
use App\Modules\Crm\Deals\Models\Deal;
use App\Modules\Projects\Tasks\Data\TaskData;
use App\Modules\Projects\Tasks\Models\Task;
use App\Support\Exceptions\OptimisticLockConflictException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class BuildTaskDataAction
{
    public function execute(User $viewer, Task $task): TaskData
    {
        $task->load([
            'project:id,code,name',
            'stage:id,name,category',
            'creator:id,name',
            'assignee:id,name',
            'watchers:id',
        ]);
        $task->loadCount('subtasks');

        return $this->mapCollection($viewer, new EloquentCollection([$task]))->first()
            ?? TaskData::fromModel($task, $viewer, false);
    }

    /**
     * @param  EloquentCollection<int, Task>  $tasks
     * @return Collection<int, TaskData>
     */
    public function mapCollection(User $viewer, EloquentCollection $tasks): Collection
    {
        if ($tasks->isEmpty()) {
            return collect();
        }

        $companyIds = [];
        $contactIds = [];
        $dealIds = [];

        foreach ($tasks as $task) {
            if ($task->linkable_id === null) {
                continue;
            }

            $id = (string) $task->linkable_id;
            match ($task->linkable_type) {
                'company' => $companyIds[] = $id,
                'contact' => $contactIds[] = $id,
                'deal' => $dealIds[] = $id,
                default => null,
            };
        }

        /** @var array<string, int|string> $visibleCompanyIds */
        $visibleCompanyIds = ($companyIds !== [] && Gate::forUser($viewer)->allows('viewAny', Company::class))
            ? Company::query()
                ->visibleTo($viewer)
                ->whereIn('id', array_values(array_unique($companyIds)))
                ->pluck('id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->flip()
                ->all()
            : [];

        /** @var array<string, int|string> $visibleContactIds */
        $visibleContactIds = ($contactIds !== [] && Gate::forUser($viewer)->allows('viewAny', Contact::class))
            ? Contact::query()
                ->visibleTo($viewer)
                ->whereIn('id', array_values(array_unique($contactIds)))
                ->pluck('id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->flip()
                ->all()
            : [];

        /** @var array<string, int|string> $visibleDealIds */
        $visibleDealIds = ($dealIds !== [] && Gate::forUser($viewer)->allows('viewAny', Deal::class))
            ? Deal::query()
                ->visibleTo($viewer)
                ->whereIn('id', array_values(array_unique($dealIds)))
                ->pluck('id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->flip()
                ->all()
            : [];

        return $tasks->map(static function (Task $task) use (
            $viewer,
            $visibleCompanyIds,
            $visibleContactIds,
            $visibleDealIds,
        ): TaskData {
            $canViewLinkable = true;

            if ($task->linkable_type !== null && $task->linkable_id !== null) {
                $linkId = (string) $task->linkable_id;
                $canViewLinkable = match ($task->linkable_type) {
                    'company' => isset($visibleCompanyIds[$linkId]),
                    'contact' => isset($visibleContactIds[$linkId]),
                    'deal' => isset($visibleDealIds[$linkId]),
                    default => false,
                };
            }

            return TaskData::fromModel($task, $viewer, $canViewLinkable);
        });
    }

    public function buildConflictException(User $viewer, Task $lockedTask): OptimisticLockConflictException
    {
        $freshData = $this->execute($viewer, $lockedTask);

        return new OptimisticLockConflictException(
            currentVersion: (int) $lockedTask->version,
            entity: $freshData->toArray(),
        );
    }
}
