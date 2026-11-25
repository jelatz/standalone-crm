<?php

namespace App\Modules\Core\Auth\Actions;

use App\Modules\Core\Auth\Data\LoginRequestData;
use App\Modules\Core\Users\Enums\UserStatusEnum;
use App\Modules\Core\Users\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthenticatePasswordUserAction
{
    public function execute(LoginRequestData $data): User
    {
        $email = mb_strtolower(trim($data->email));

        $user = User::withTrashed()
            ->where('email', $email)
            ->first();

        if ($user === null || $user->password === null || ! Hash::check($data->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        if ($user->trashed() || $user->status !== UserStatusEnum::Active) {
            throw ValidationException::withMessages([
                'email' => 'This employee account is inactive.',
            ]);
        }

        $user->forceFill(['last_seen_at' => now()])->save();

        return $user;
    }
}
