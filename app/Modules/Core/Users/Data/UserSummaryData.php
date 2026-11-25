<?php

namespace App\Modules\Core\Users\Data;

use App\Modules\Core\Users\Models\User;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class UserSummaryData extends Data
{
    /**
     * @param  array<int, string>  $roles
     */
    public function __construct(
        public string $id,
        public string $employee_code,
        public string $name,
        public string $email,
        public ?string $job_title,
        public string $department_id,
        public string $department_name,
        public string $status,
        public array $roles,
        public ?string $created_at,
    ) {}

    public static function fromModel(User $user): self
    {
        return new self(
            id: (string) $user->id,
            employee_code: $user->employee_code,
            name: $user->name,
            email: $user->email,
            job_title: $user->job_title,
            department_id: (string) $user->department_id,
            department_name: $user->department?->name ?? 'General',
            status: $user->status->value,
            roles: $user->getRoleNames()->values()->all(),
            created_at: $user->created_at?->toIso8601String(),
        );
    }
}
