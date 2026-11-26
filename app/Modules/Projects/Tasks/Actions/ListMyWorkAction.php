<?php

namespace App\Modules\Projects\Tasks\Actions;

use App\Modules\Core\Users\Models\User;
use App\Modules\Projects\Tasks\Data\MyWorkGroupData;
use App\Modules\Projects\Tasks\Data\TaskData;
use App\Modules\Projects\Tasks\Enums\TaskPriorityEnum;
use App\Modules\Projects\Tasks\Enums\TaskStatusEnum;
use App\Modules\Projects\Tasks\Models\Task;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

class ListMyWorkAction
{
    public function __construct(
        protected BuildTaskDataAction $buildTaskData,
    ) {}

    /**
     * @return array{
     *     groups: list<MyWorkGroupData>,
     *     totalCount: int,
     *     priorities: list<string>,
     *     statuses: list<string>
     * }
     */
    public function execute(User $viewer): array
    {
        Gate::forUser($viewer)->authorize('viewAny', Task::class);

        $query = Task::query()
            ->where('assignee_id', $viewer->id)
            ->whereNotIn('status', [
                TaskStatusEnum::Done->value,
                TaskStatusEnum::Cancelled->value,
            ])
            ->where(static function (Builder $sub): void {
                $sub->whereNull('project_id')
                    ->orWhereHas('project');
            })
            ->where(static function (Builder $sub): void {
                $sub->whereNull('parent_task_id')
                    ->orWhereHas('parent');
            })
            ->with([
                'project',
                'parent.project',
                'stage:id,name,category',
                'creator:id,name',
                'assignee:id,name',
                'watchers:id',
            ])
            ->withCount('subtasks');

        if (DB::getDriverName() === 'pgsql') {
            $query->orderByRaw('due_at ASC NULLS LAST');
        } else {
            $query->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END, due_at ASC');
        }

        $tasks = $query
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->filter(static fn (Task $task): bool => Gate::forUser($viewer)->allows('view', $task))
            ->values();

        /** @var EloquentCollection<int, Task> $visibleCollection */
        $visibleCollection = new EloquentCollection($tasks->all());
        $taskDtos = $this->buildTaskData->mapCollection($viewer, $visibleCollection);

        $timezone = $this->resolveTimezone($viewer);
        $now = now()->toImmutable()->setTimezone($timezone);
        $startOfToday = $now->startOfDay();
        $endOfToday = $now->endOfDay();
        $endOfThisWeek = $startOfToday->addDays(7)->endOfDay();

        /** @var array<string, list<TaskData>> $buckets */
        $buckets = [
            'overdue' => [],
            'today' => [],
            'this_week' => [],
            'later' => [],
        ];

        foreach ($visibleCollection as $index => $taskModel) {
            /** @var TaskData $taskDto */
            $taskDto = $taskDtos->get($index);

            if ($taskModel->due_at === null) {
                $buckets['later'][] = $taskDto;

                continue;
            }

            $dueInViewerTz = CarbonImmutable::instance($taskModel->due_at)->setTimezone($timezone);

            if ($dueInViewerTz->lt($startOfToday)) {
                $buckets['overdue'][] = $taskDto;
            } elseif ($dueInViewerTz->lte($endOfToday)) {
                $buckets['today'][] = $taskDto;
            } elseif ($dueInViewerTz->lte($endOfThisWeek)) {
                $buckets['this_week'][] = $taskDto;
            } else {
                $buckets['later'][] = $taskDto;
            }
        }

        $groupLabels = [
            'overdue' => 'Overdue',
            'today' => 'Today',
            'this_week' => 'This Week',
            'later' => 'Later',
        ];

        $groups = [];
        foreach ($groupLabels as $key => $label) {
            $items = $buckets[$key];
            $groups[] = new MyWorkGroupData(
                key: $key,
                label: $label,
                tasks_count: count($items),
                tasks: $items,
            );
        }

        return [
            'groups' => $groups,
            'totalCount' => $visibleCollection->count(),
            'priorities' => array_map(static fn (TaskPriorityEnum $p): string => $p->value, TaskPriorityEnum::cases()),
            'statuses' => array_map(static fn (TaskStatusEnum $s): string => $s->value, TaskStatusEnum::cases()),
        ];
    }

    protected function resolveTimezone(User $viewer): string
    {
        $candidate = is_string($viewer->timezone) && trim($viewer->timezone) !== ''
            ? trim($viewer->timezone)
            : (string) config('app.timezone', 'UTC');

        try {
            new DateTimeZone($candidate);

            return $candidate;
        } catch (Throwable) {
            return 'UTC';
        }
    }
}
