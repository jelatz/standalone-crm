<?php

namespace App\Modules\Core\Users\Data;

use App\Modules\Core\Rbac\Enums\RoleEnum;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;

class CreateUserData extends Data
{
    public function __construct(
        public string $name,
        public string $email,
        public string $password,
        public RoleEnum $role,
        public ?string $department_id = null,
        public ?string $job_title = null,
        public ?string $employee_code = null,
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', Rule::enum(RoleEnum::class)],
            'department_id' => ['nullable', 'uuid', 'exists:departments,id'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'employee_code' => ['nullable', 'string', 'max:32', Rule::unique('users', 'employee_code')],
        ];
    }
}
