<?php

namespace App\Modules\Core\Auth\Data;

use App\Modules\Core\Users\Models\User;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class AuthenticatedUserData extends Data
{
    /**
     * @param  array<int, string>  $roles
     * @param  array<int, string>  $permissions
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $email,
        public string $employee_code,
        public ?string $job_title,
        public string $department_id,
        public array $roles,
        public array $permissions,
    ) {}

    public static function fromModel(User $user): self
    {
        return new self(
            id: (string) $user->id,
            name: $user->name,
            email: $user->email,
            employee_code: $user->employee_code,
            job_title: $user->job_title,
            department_id: (string) $user->department_id,
            roles: $user->getRoleNames()->values()->all(),
            permissions: $user->getAllPermissions()->pluck('name')->values()->all(),
        );
    }
}
