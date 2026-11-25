<?php

namespace App\Modules\Core\Users\Actions;

use App\Modules\Core\Users\Data\CreateUserData;
use App\Modules\Core\Users\Enums\UserStatusEnum;
use App\Modules\Core\Users\Models\Department;
use App\Modules\Core\Users\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateUserAction
{
    public function __construct(
        protected RebuildUserHierarchyClosureAction $rebuildHierarchy,
    ) {}

    public function execute(CreateUserData $data): User
    {
        return DB::transaction(function () use ($data): User {
            $departmentId = $data->department_id !== null && trim($data->department_id) !== ''
                ? trim($data->department_id)
                : (string) Department::query()->firstOrCreate(
                    ['slug' => 'general'],
                    ['name' => 'General'],
                )->id;

            $rawCode = $data->employee_code !== null ? trim($data->employee_code) : '';
            $employeeCode = $rawCode !== ''
                ? $rawCode
                : 'EMP-'.strtoupper(substr(str_replace('-', '', (string) Str::uuid7()), -10));

            $jobTitle = $data->job_title !== null && trim($data->job_title) !== ''
                ? trim($data->job_title)
                : null;

            $user = User::query()->create([
                'employee_code' => $employeeCode,
                'name' => trim($data->name),
                'email' => mb_strtolower(trim($data->email)),
                'password' => $data->password,
                'department_id' => $departmentId,
                'job_title' => $jobTitle,
                'status' => UserStatusEnum::Active,
            ]);

            $user->assignRole($data->role->value);
            $this->rebuildHierarchy->execute($user);

            return $user->load(['department', 'roles']);
        });
    }
}
