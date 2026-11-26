<?php

namespace App\Modules\Crm\Notes\Actions;

use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Notes\Data\CreateNoteData;
use App\Modules\Crm\Notes\Data\NoteTargetData;
use App\Modules\Crm\Notes\Models\Note;
use App\Modules\Crm\Timeline\Actions\RecordActivityAction;
use App\Modules\Crm\Timeline\Enums\ActivityTypeEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateNoteAction
{
    public function __construct(
        protected SanitizeTipTapAstAction $sanitizer,
        protected RecordActivityAction $recordActivity,
    ) {}

    public function execute(User $actor, CreateNoteData $data): Note
    {
        $resolvedTargets = $this->resolveTargets($data->targets);

        if ($resolvedTargets === []) {
            throw ValidationException::withMessages([
                'targets' => ['At least one valid CRM target entity is required.'],
            ]);
        }

        $primaryTarget = $resolvedTargets[0]['model'];
        Gate::forUser($actor)->authorize('create', [Note::class, $primaryTarget]);

        foreach ($resolvedTargets as $resolvedTarget) {
            Gate::forUser($actor)->authorize('update', $resolvedTarget['model']);
        }

        $sanitized = $this->sanitizer->execute($data->body_json, requireNonEmpty: true);

        $activityTargets = array_map(
            static fn (array $item): array => [
                'linkable_type' => $item['linkable_type'],
                'linkable_id' => $item['linkable_id'],
            ],
            $resolvedTargets,
        );

        return DB::transaction(function () use ($actor, $sanitized, $data, $activityTargets): Note {
            $note = Note::query()->create([
                'author_id' => $actor->id,
                'body_json' => $sanitized['body_json'],
                'body_text' => $sanitized['body_text'],
                'is_pinned' => $data->is_pinned,
            ]);

            $this->recordActivity->execute(
                actor: $actor,
                activityType: ActivityTypeEnum::NoteCreated,
                sourceType: $note->getMorphClass(),
                sourceId: (string) $note->id,
                targets: $activityTargets,
                summary: Str::limit($sanitized['body_text'], 255, ''),
                metadata: [
                    'is_pinned' => $note->is_pinned,
                ],
            );

            return $note->load('author');
        });
    }

    /**
     * @param  list<NoteTargetData|array{linkable_type: string, linkable_id: string}>  $targets
     * @return list<array{linkable_type: string, linkable_id: string, model: Model}>
     */
    protected function resolveTargets(array $targets): array
    {
        $resolved = [];

        foreach ($targets as $target) {
            $type = $target instanceof NoteTargetData ? $target->linkable_type : (string) ($target['linkable_type'] ?? '');
            $id = $target instanceof NoteTargetData ? $target->linkable_id : (string) ($target['linkable_id'] ?? '');

            if (! in_array($type, ['lead', 'company', 'contact', 'deal'], true) || ! Str::isUuid($id)) {
                throw ValidationException::withMessages([
                    'targets' => ['All note targets must reference valid lead, company, contact, or deal UUIDs.'],
                ]);
            }

            $key = "{$type}:{$id}";
            if (isset($resolved[$key])) {
                continue;
            }

            $modelClass = Relation::getMorphedModel($type);
            if ($modelClass === null || ! class_exists($modelClass)) {
                throw ValidationException::withMessages([
                    'targets' => ["Unknown target type [{$type}]."],
                ]);
            }

            $model = $modelClass::query()->findOrFail($id);
            $resolved[$key] = [
                'linkable_type' => $type,
                'linkable_id' => $id,
                'model' => $model,
            ];
        }

        return array_values($resolved);
    }
}
