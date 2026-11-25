<?php

namespace Database\Seeders;

use App\Modules\Core\Rbac\Enums\RoleEnum;
use App\Modules\Core\Users\Actions\RebuildUserHierarchyClosureAction;
use App\Modules\Core\Users\Enums\UserStatusEnum;
use App\Modules\Core\Users\Models\Department;
use App\Modules\Core\Users\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RolesPermissionsSeeder::class);

        $department = Department::query()->firstOrCreate(
            ['slug' => 'general'],
            ['name' => 'General'],
        );

        $user = User::query()->firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'employee_code' => 'EMP-0001',
                'name' => 'Admin User',
                'department_id' => $department->id,
                'job_title' => 'System Administrator',
                'status' => UserStatusEnum::Active,
            ],
        );

        if (! $user->hasRole(RoleEnum::Admin->value)) {
            $user->assignRole(RoleEnum::Admin->value);
        }

        app(RebuildUserHierarchyClosureAction::class)->execute($user);
    }
}
