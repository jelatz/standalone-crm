<?php

namespace App\Modules\Crm\Notes\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Notes\Actions\CreateNoteAction;
use App\Modules\Crm\Notes\Actions\DeleteNoteAction;
use App\Modules\Crm\Notes\Actions\TogglePinNoteAction;
use App\Modules\Crm\Notes\Actions\UpdateNoteAction;
use App\Modules\Crm\Notes\Data\CreateNoteData;
use App\Modules\Crm\Notes\Data\NoteData;
use App\Modules\Crm\Notes\Data\NoteTargetData;
use App\Modules\Crm\Notes\Data\UpdateNoteData;
use App\Modules\Crm\Notes\Models\Note;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class NoteController extends Controller
{
    public function store(
        Request $request,
        CreateNoteAction $createNote,
    ): JsonResponse {
        Gate::forUser($request->user())->authorize('create', Note::class);

        $data = CreateNoteData::validateAndCreate($request->all());

        /** @var list<Model> $targetModels */
        $targetModels = [];
        foreach ($data->targets as $target) {
            /** @var NoteTargetData $target */
            $modelClass = Relation::getMorphedModel($target->linkable_type);
            abort_if($modelClass === null || ! class_exists($modelClass), 422);
            $targetModels[] = $modelClass::query()->findOrFail($target->linkable_id);
        }

        $primaryTarget = $targetModels[0] ?? null;
        Gate::forUser($request->user())->authorize('create', [Note::class, $primaryTarget]);

        foreach ($targetModels as $targetModel) {
            Gate::forUser($request->user())->authorize('update', $targetModel);
        }

        /** @var User $actor */
        $actor = $request->user();
        $note = $createNote->execute($actor, $data);

        return response()->json(NoteData::fromModel($note)->toArray(), 201);
    }

    public function update(
        Note $note,
        Request $request,
        UpdateNoteAction $updateNote,
    ): JsonResponse {
        Gate::forUser($request->user())->authorize('update', $note);

        $data = UpdateNoteData::validateAndCreate($request->all());

        /** @var User $actor */
        $actor = $request->user();
        $updated = $updateNote->execute($actor, $note, $data);

        return response()->json(NoteData::fromModel($updated)->toArray(), 200);
    }

    public function destroy(
        Note $note,
        Request $request,
        DeleteNoteAction $deleteNote,
    ): JsonResponse {
        Gate::forUser($request->user())->authorize('delete', $note);

        /** @var User $actor */
        $actor = $request->user();
        $deleteNote->execute($actor, $note);

        return response()->json(['deleted' => true], 200);
    }

    public function togglePin(
        Note $note,
        Request $request,
        TogglePinNoteAction $togglePin,
    ): JsonResponse {
        Gate::forUser($request->user())->authorize('update', $note);

        /** @var array{is_pinned?: bool} $validated */
        $validated = $request->validate([
            'is_pinned' => ['sometimes', 'boolean'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $updated = $togglePin->execute(
            actor: $actor,
            note: $note,
            pinned: array_key_exists('is_pinned', $validated) ? (bool) $validated['is_pinned'] : null,
        );

        return response()->json(NoteData::fromModel($updated)->toArray(), 200);
    }
}
