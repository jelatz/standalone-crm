<?php

namespace App\Modules\Crm\Timeline\Actions;

use App\Modules\Core\Rbac\Enums\RoleEnum;
use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Companies\Models\Company;
use App\Modules\Crm\Contacts\Models\Contact;
use App\Modules\Crm\Deals\Models\Deal;
use App\Modules\Crm\Notes\Data\NoteData;
use App\Modules\Crm\Notes\Models\Note;
use App\Modules\Crm\Timeline\Data\ActivityData;
use App\Modules\Crm\Timeline\Data\TimelinePageData;
use App\Modules\Crm\Timeline\Models\Activity;
use App\Modules\Crm\Timeline\Models\ActivityLink;
use App\Modules\Projects\Handoffs\Models\DealHandoff;
use App\Modules\Projects\Projects\Models\Project;
use App\Modules\Projects\Tasks\Models\Task;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class QueryEntityTimelineAction
{
    /**
     * @var list<string>
     */
    public const array ALLOWED_LINKABLE_TYPES = [
        'company',
        'contact',
        'deal',
        'project',
    ];

    /**
     * @var list<string>
     */
    public const array ALLOWED_SOURCE_TYPES = [
        'deal',
        'contact',
        'company',
        'note',
        'project',
        'task',
        'deal_handoff',
    ];

    public const int MAX_SCAN_BATCHES = 20;

    public function execute(
        User $viewer,
        string $linkableType,
        string $linkableId,
        ?string $cursor = null,
        int $limit = 25,
    ): TimelinePageData {
        if (! in_array($linkableType, self::ALLOWED_LINKABLE_TYPES, true)) {
            throw ValidationException::withMessages([
                'linkable_type' => ['Unsupported timeline target entity type.'],
            ]);
        }

        if (! Str::isUuid($linkableId)) {
            throw ValidationException::withMessages([
                'linkable_id' => ['Invalid timeline target UUID.'],
            ]);
        }

        $modelClass = Relation::getMorphedModel($linkableType);
        if ($modelClass === null || ! class_exists($modelClass)) {
            throw ValidationException::withMessages([
                'linkable_type' => ['Unregistered timeline target entity type.'],
            ]);
        }

        $targetEntity = $modelClass::query()->findOrFail($linkableId);
        Gate::forUser($viewer)->authorize('view', $targetEntity);

        $clampedLimit = max(1, min($limit, 100));
        $scanCursor = $cursor !== null && trim($cursor) !== ''
            ? $this->decodeCursor($cursor)
            : null;

        $batchSize = max($clampedLimit * 2, 50);
        $isAdmin = $viewer->hasAnyRole([RoleEnum::Superadmin->value, RoleEnum::Admin->value]);
        $canViewDeals = Gate::forUser($viewer)->allows('viewAny', Deal::class);
        $canViewContacts = Gate::forUser($viewer)->allows('viewAny', Contact::class);
        $canViewCompanies = Gate::forUser($viewer)->allows('viewAny', Company::class);
        $canViewProjects = Gate::forUser($viewer)->allows('viewAny', Project::class);
        $canViewTasks = Gate::forUser($viewer)->allows('viewAny', Task::class);
        $canViewHandoffs = Gate::forUser($viewer)->allows('viewAny', DealHandoff::class);
        $batchesScanned = 0;

        /** @var list<array{data: ActivityData, occurred_at: CarbonInterface, activity_id: string}> $visibleEntries */
        $visibleEntries = [];

        while (count($visibleEntries) <= $clampedLimit && $batchesScanned < self::MAX_SCAN_BATCHES) {
            $batchesScanned++;
            $query = ActivityLink::query()
                ->with('activity.actor')
                ->where('linkable_type', $linkableType)
                ->where('linkable_id', $linkableId);

            if ($scanCursor !== null) {
                [$cursorTimestamp, $cursorActivityId] = $scanCursor;
                $cursorTimeString = $cursorTimestamp->utc()->format('Y-m-d H:i:s.u+00');

                $query->where(function ($sub) use ($cursorTimeString, $cursorActivityId): void {
                    $sub->where('occurred_at', '<', $cursorTimeString)
                        ->orWhere(function ($tie) use ($cursorTimeString, $cursorActivityId): void {
                            $tie->where('occurred_at', '=', $cursorTimeString)
                                ->where('activity_id', '<', $cursorActivityId);
                        });
                });
            }

            $links = $query
                ->orderByDesc('occurred_at')
                ->orderByDesc('activity_id')
                ->limit($batchSize)
                ->get();

            if ($links->isEmpty()) {
                break;
            }

            $lastLink = $links->last();
            $scanCursor = [$lastLink->occurred_at, (string) $lastLink->activity_id];

            $dealIds = [];
            $contactIds = [];
            $companyIds = [];
            $noteIds = [];
            $projectIds = [];
            $taskIds = [];
            $handoffIds = [];

            foreach ($links as $link) {
                $activity = $link->activity;
                if (! $activity instanceof Activity) {
                    continue;
                }

                match ($activity->source_type) {
                    'deal' => $dealIds[] = (string) $activity->source_id,
                    'contact' => $contactIds[] = (string) $activity->source_id,
                    'company' => $companyIds[] = (string) $activity->source_id,
                    'note' => $noteIds[] = (string) $activity->source_id,
                    'project' => $projectIds[] = (string) $activity->source_id,
                    'task' => $taskIds[] = (string) $activity->source_id,
                    'deal_handoff' => $handoffIds[] = (string) $activity->source_id,
                    default => null,
                };
            }

            $visibleDealIds = ($canViewDeals && $dealIds !== [])
                ? Deal::query()->visibleTo($viewer)->whereIn('id', array_values(array_unique($dealIds)))->pluck('id')->map(static fn (mixed $id): string => (string) $id)->flip()->all()
                : [];

            $visibleContactIds = ($canViewContacts && $contactIds !== [])
                ? Contact::query()->visibleTo($viewer)->whereIn('id', array_values(array_unique($contactIds)))->pluck('id')->map(static fn (mixed $id): string => (string) $id)->flip()->all()
                : [];

            $visibleCompanyIds = ($canViewCompanies && $companyIds !== [])
                ? Company::query()->visibleTo($viewer)->whereIn('id', array_values(array_unique($companyIds)))->pluck('id')->map(static fn (mixed $id): string => (string) $id)->flip()->all()
                : [];

            $visibleProjectIds = ($canViewProjects && $projectIds !== [])
                ? Project::query()->visibleTo($viewer)->whereIn('id', array_values(array_unique($projectIds)))->pluck('id')->map(static fn (mixed $id): string => (string) $id)->flip()->all()
                : [];

            $notesById = $noteIds !== []
                ? Note::query()->with('author')->whereIn('id', array_values(array_unique($noteIds)))->get()->keyBy(static fn (Note $note): string => (string) $note->id)
                : collect();

            $tasksById = ($canViewTasks && $taskIds !== [])
                ? Task::query()->with(['parent.project', 'project'])->whereIn('id', array_values(array_unique($taskIds)))->get()->keyBy(static fn (Task $task): string => (string) $task->id)
                : collect();

            $handoffsById = ($canViewHandoffs && $handoffIds !== [])
                ? DealHandoff::query()->with('deal')->whereIn('id', array_values(array_unique($handoffIds)))->get()->keyBy(static fn (DealHandoff $handoff): string => (string) $handoff->id)
                : collect();

            foreach ($links as $link) {
                $activity = $link->activity;
                if (! $activity instanceof Activity) {
                    continue;
                }

                if (
                    $activity->visibility_mode === 'private_to_actor'
                    && ! $isAdmin
                    && (string) $activity->actor_id !== (string) $viewer->id
                ) {
                    continue;
                }

                $sourceId = (string) $activity->source_id;
                $noteData = null;

                if (! in_array($activity->source_type, self::ALLOWED_SOURCE_TYPES, true)) {
                    continue;
                }

                if ($activity->source_type === 'deal') {
                    if (! isset($visibleDealIds[$sourceId])) {
                        continue;
                    }
                } elseif ($activity->source_type === 'contact') {
                    if (! isset($visibleContactIds[$sourceId])) {
                        continue;
                    }
                } elseif ($activity->source_type === 'company') {
                    if (! isset($visibleCompanyIds[$sourceId])) {
                        continue;
                    }
                } elseif ($activity->source_type === 'project') {
                    if (! isset($visibleProjectIds[$sourceId])) {
                        continue;
                    }
                } elseif ($activity->source_type === 'task') {
                    if (! $canViewTasks) {
                        continue;
                    }

                    /** @var Task|null $task */
                    $task = $tasksById->get($sourceId);
                    if ($task === null || ! Gate::forUser($viewer)->allows('view', $task)) {
                        continue;
                    }
                } elseif ($activity->source_type === 'deal_handoff') {
                    if (! $canViewHandoffs) {
                        continue;
                    }

                    /** @var DealHandoff|null $handoff */
                    $handoff = $handoffsById->get($sourceId);
                    if ($handoff === null || ! Gate::forUser($viewer)->allows('view', $handoff)) {
                        continue;
                    }
                } elseif ($activity->source_type === 'note') {
                    /** @var Note|null $note */
                    $note = $notesById->get($sourceId);
                    if ($note === null || ! Gate::forUser($viewer)->allows('view', $note)) {
                        continue;
                    }

                    $noteData = NoteData::fromModel($note);
                } else {
                    continue;
                }

                $visibleEntries[] = [
                    'data' => ActivityData::fromModel($activity, $noteData),
                    'occurred_at' => $link->occurred_at,
                    'activity_id' => (string) $link->activity_id,
                ];

                if (count($visibleEntries) > $clampedLimit) {
                    break;
                }
            }

            if ($links->count() < $batchSize) {
                break;
            }
        }

        $hasMore = count($visibleEntries) > $clampedLimit;
        $pageEntries = array_slice($visibleEntries, 0, $clampedLimit);

        $nextCursor = null;
        if ($hasMore && $pageEntries !== []) {
            $lastVisible = $pageEntries[array_key_last($pageEntries)];
            $nextCursor = $this->encodeCursor($lastVisible['occurred_at'], $lastVisible['activity_id']);
        }

        return new TimelinePageData(
            items: array_column($pageEntries, 'data'),
            next_cursor: $nextCursor,
            has_more: $hasMore,
        );
    }

    /**
     * @return array{0: CarbonImmutable, 1: string}
     */
    protected function decodeCursor(string $cursor): array
    {
        $decodedJson = base64_decode($cursor, true);
        if ($decodedJson === false) {
            throw ValidationException::withMessages([
                'cursor' => ['The timeline cursor is malformed.'],
            ]);
        }

        try {
            $payload = json_decode($decodedJson, true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'cursor' => ['The timeline cursor is invalid JSON.'],
            ]);
        }

        if (
            ! is_array($payload)
            || ! is_string($payload['occurred_at'] ?? null)
            || ! is_string($payload['activity_id'] ?? null)
            || ! Str::isUuid($payload['activity_id'])
        ) {
            throw ValidationException::withMessages([
                'cursor' => ['The timeline cursor payload is invalid.'],
            ]);
        }

        $timestamp = null;
        foreach (['!Y-m-d\TH:i:s.uP', '!Y-m-d\TH:i:s.u+00', '!'.CarbonInterface::ATOM] as $format) {
            try {
                $parsed = CarbonImmutable::createFromFormat($format, $payload['occurred_at'], 'UTC');
                $lastErrors = CarbonImmutable::getLastErrors();
                if (
                    $parsed instanceof CarbonImmutable
                    && ($lastErrors === false || ($lastErrors['warning_count'] === 0 && $lastErrors['error_count'] === 0))
                ) {
                    $timestamp = $parsed->utc();
                    break;
                }
            } catch (Throwable) {
                // Try next strict ISO-8601 format.
            }
        }

        if (! $timestamp instanceof CarbonImmutable) {
            throw ValidationException::withMessages([
                'cursor' => ['The timeline cursor timestamp is invalid.'],
            ]);
        }

        return [$timestamp, $payload['activity_id']];
    }

    protected function encodeCursor(CarbonInterface $occurredAt, string $activityId): string
    {
        $json = json_encode([
            'occurred_at' => $occurredAt->utc()->format('Y-m-d\TH:i:s.uP'),
            'activity_id' => $activityId,
        ], JSON_THROW_ON_ERROR);

        return base64_encode($json);
    }
}
