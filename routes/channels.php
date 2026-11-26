<?php

use App\Modules\Core\Users\Models\User;
use App\Modules\Projects\Projects\Models\Project;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

Broadcast::channel('user.{userId}', static function (User $user, string $userId): bool {
    return (string) $user->id === $userId;
});

Broadcast::channel('project-board.{projectId}', static function (User $user, string $projectId): array|bool {
    if (! Str::isUuid($projectId)) {
        return false;
    }

    $project = Project::query()->find($projectId);

    if ($project === null || ! Gate::forUser($user)->allows('view', $project)) {
        return false;
    }

    return [
        'id' => (string) $user->id,
        'name' => $user->name,
    ];
});
