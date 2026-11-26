<?php

use App\Modules\Core\Users\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('user.{userId}', static function (User $user, string $userId): bool {
    return (string) $user->id === $userId;
});
