<?php

namespace App\Modules\Crm\Timeline\Actions;

use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Timeline\Enums\ActivityTypeEnum;
use App\Modules\Crm\Timeline\Models\Activity;
use App\Modules\Crm\Timeline\Models\ActivityLink;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class RecordActivityAction
{
    /**
     * Persist an Activity and fan out deduplicated ActivityLink rows atomically.
     *
     * @param  array<mixed, mixed>  $targets
     * @param  array<string, mixed>  $metadata
     */
    public function execute(
        ?User $actor,
        ActivityTypeEnum $activityType,
        string $sourceType,
        string $sourceId,
        array $targets,
        string $summary,
        array $metadata = [],
        string $visibilityMode = 'source_policy',
        ?CarbonInterface $occurredAt = null,
    ): Activity {
        $appTimezone = (string) config('app.timezone', 'UTC');
        $timestamp = ($occurredAt !== null
            ? CarbonImmutable::instance($occurredAt)
            : now()->toImmutable())->setTimezone($appTimezone);

        $normalizedTargets = $this->normalizeTargets($targets);

        return DB::transaction(function () use (
            $actor,
            $activityType,
            $sourceType,
            $sourceId,
            $normalizedTargets,
            $summary,
            $metadata,
            $visibilityMode,
            $timestamp,
        ): Activity {
            $activity = Activity::query()->create([
                'actor_id' => $actor?->id,
                'activity_type' => $activityType,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'visibility_mode' => $visibilityMode,
                'summary' => Str::limit($summary, 255, ''),
                'metadata' => $metadata,
                'occurred_at' => $timestamp,
                'created_at' => now()->toImmutable(),
            ]);

            if ($normalizedTargets !== []) {
                $formattedTimestamp = $timestamp->utc()->format('Y-m-d H:i:s.u+00');
                $rows = array_map(static fn (array $target): array => [
                    'linkable_type' => $target['linkable_type'],
                    'linkable_id' => $target['linkable_id'],
                    'occurred_at' => $formattedTimestamp,
                    'activity_id' => (string) $activity->id,
                ], $normalizedTargets);

                ActivityLink::query()->insert($rows);
            }

            return $activity;
        });
    }

    /**
     * Normalize target references into deduplicated (linkable_type, linkable_id) pairs.
     *
     * @param  array<mixed, mixed>  $targets
     * @return list<array{linkable_type: string, linkable_id: string}>
     */
    protected function normalizeTargets(array $targets): array
    {
        $deduped = [];

        $register = static function (string $type, ?string $id) use (&$deduped): void {
            if ($id === null || $id === '') {
                return;
            }

            if (Relation::getMorphedModel($type) === null) {
                throw new InvalidArgumentException("Unknown morph alias [{$type}] for activity target.");
            }

            if (! Str::isUuid($id)) {
                throw new InvalidArgumentException("Invalid UUID [{$id}] for activity target [{$type}].");
            }

            $deduped["{$type}:{$id}"] = [
                'linkable_type' => $type,
                'linkable_id' => $id,
            ];
        };

        foreach ($targets as $key => $value) {
            if ($value === null) {
                continue;
            }

            if (is_string($key)) {
                if (is_array($value)) {
                    foreach ($value as $nestedId) {
                        $register($key, is_string($nestedId) ? $nestedId : null);
                    }
                } elseif (is_string($value)) {
                    $register($key, $value);
                }

                continue;
            }

            if ($value instanceof Model) {
                $register($value->getMorphClass(), (string) $value->getKey());

                continue;
            }

            if (is_array($value)) {
                $type = $value['linkable_type'] ?? $value['type'] ?? null;
                $id = $value['linkable_id'] ?? $value['id'] ?? null;

                if (is_string($type) && is_string($id)) {
                    $register($type, $id);
                }
            }
        }

        return array_values($deduped);
    }
}
