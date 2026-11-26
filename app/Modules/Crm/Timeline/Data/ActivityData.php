<?php

namespace App\Modules\Crm\Timeline\Data;

use App\Modules\Crm\Notes\Data\NoteData;
use App\Modules\Crm\Timeline\Enums\ActivityTypeEnum;
use App\Modules\Crm\Timeline\Models\Activity;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ActivityData extends Data
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $id,
        public ?string $actor_id,
        public ?string $actor_name,
        public ActivityTypeEnum $activity_type,
        public string $source_type,
        public string $source_id,
        public string $visibility_mode,
        public string $summary,
        public array $metadata,
        public string $occurred_at,
        public ?NoteData $note = null,
    ) {}

    /**
     * @param  array<string, mixed>|null  $metadataOverride
     */
    public static function fromModel(Activity $activity, ?NoteData $note = null, ?array $metadataOverride = null): self
    {
        $actorName = $activity->relationLoaded('actor') ? $activity->actor?->name : null;

        return new self(
            id: (string) $activity->id,
            actor_id: $activity->actor_id !== null ? (string) $activity->actor_id : null,
            actor_name: $actorName,
            activity_type: $activity->activity_type,
            source_type: $activity->source_type,
            source_id: (string) $activity->source_id,
            visibility_mode: $activity->visibility_mode,
            summary: $activity->summary,
            metadata: $metadataOverride ?? ($activity->metadata ?? []),
            occurred_at: $activity->occurred_at->toIso8601String(),
            note: $note,
        );
    }
}
