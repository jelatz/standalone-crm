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

        $superadminEmail = mb_strtolower(trim((string) env('SEED_SUPERADMIN_EMAIL', 'superadmin@example.com')));
        $superadminPassword = (string) env('SEED_SUPERADMIN_PASSWORD', 'SuperAdmin123!');

        $superadmin = User::query()->firstOrCreate(
            ['email' => $superadminEmail],
            [
                'employee_code' => 'EMP-0000',
                'name' => 'Super Admin',
                'password' => $superadminPassword,
                'department_id' => $department->id,
                'job_title' => 'Super Administrator',
                'status' => UserStatusEnum::Active,
            ],
        );

        if ($superadmin->password === null) {
            $superadmin->forceFill(['password' => $superadminPassword])->save();
        }

        if (! $superadmin->hasRole(RoleEnum::Superadmin->value)) {
            $superadmin->assignRole(RoleEnum::Superadmin->value);
        }

        app(RebuildUserHierarchyClosureAction::class)->execute($superadmin);

        $adminEmail = mb_strtolower(trim((string) env('SEED_ADMIN_EMAIL', 'admin@example.com')));
        $adminPassword = (string) env('SEED_ADMIN_PASSWORD', 'Admin123!');

        $user = User::query()->firstOrCreate(
            ['email' => $adminEmail],
            [
                'employee_code' => 'EMP-0001',
                'name' => 'Admin User',
                'password' => $adminPassword,
                'department_id' => $department->id,
                'job_title' => 'System Administrator',
                'status' => UserStatusEnum::Active,
            ],
        );

        if ($user->password === null) {
            $user->forceFill(['password' => $adminPassword])->save();
        }

        if (! $user->hasRole(RoleEnum::Admin->value)) {
            $user->assignRole(RoleEnum::Admin->value);
        }

        app(RebuildUserHierarchyClosureAction::class)->execute($user);

        $this->call(CrmDefaultsSeeder::class);
        $this->call(ProjectDefaultsSeeder::class);
    }
}
