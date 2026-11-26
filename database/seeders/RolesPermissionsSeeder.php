<?php

namespace Database\Seeders;

use App\Modules\Core\Rbac\Enums\PermissionEnum;
use App\Modules\Core\Rbac\Enums\RoleEnum;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        foreach (PermissionEnum::cases() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }

        $registrar->forgetCachedPermissions();

        $matrix = $this->rolePermissionsMatrix();

        foreach (RoleEnum::cases() as $roleEnum) {
            $role = Role::findOrCreate($roleEnum->value, 'web');
            $permissions = array_map(
                static fn (PermissionEnum $perm): string => $perm->value,
                $matrix[$roleEnum->value] ?? [],
            );

            $role->syncPermissions($permissions);
        }

        $registrar->forgetCachedPermissions();
    }

    /**
     * @return array<string, list<PermissionEnum>>
     */
    protected function rolePermissionsMatrix(): array
    {
        return [
            RoleEnum::Superadmin->value => PermissionEnum::cases(),
            RoleEnum::Admin->value => PermissionEnum::cases(),

            RoleEnum::Director->value => [
                PermissionEnum::LeadsView,
                PermissionEnum::LeadsCreate,
                PermissionEnum::LeadsUpdate,
                PermissionEnum::LeadsDelete,
                PermissionEnum::LeadsConvert,
                PermissionEnum::CompaniesView,
                PermissionEnum::CompaniesCreate,
                PermissionEnum::CompaniesUpdate,
                PermissionEnum::CompaniesDelete,
                PermissionEnum::ContactsView,
                PermissionEnum::ContactsCreate,
                PermissionEnum::ContactsUpdate,
                PermissionEnum::ContactsDelete,
                PermissionEnum::DealsView,
                PermissionEnum::DealsCreate,
                PermissionEnum::DealsUpdate,
                PermissionEnum::DealsDelete,
                PermissionEnum::DealsMoveStage,
                PermissionEnum::DealsOverrideGates,
                PermissionEnum::PipelinesView,
                PermissionEnum::CustomFieldsView,
            ],

            RoleEnum::OperationsManager->value => [
                PermissionEnum::LeadsView,
                PermissionEnum::CompaniesView,
                PermissionEnum::ContactsView,
                PermissionEnum::DealsView,
                PermissionEnum::PipelinesView,
                PermissionEnum::CustomFieldsView,
            ],

            RoleEnum::Sales->value => [
                PermissionEnum::LeadsView,
                PermissionEnum::LeadsCreate,
                PermissionEnum::LeadsUpdate,
                PermissionEnum::LeadsDelete,
                PermissionEnum::LeadsConvert,
                PermissionEnum::CompaniesView,
                PermissionEnum::CompaniesCreate,
                PermissionEnum::CompaniesUpdate,
                PermissionEnum::CompaniesDelete,
                PermissionEnum::ContactsView,
                PermissionEnum::ContactsCreate,
                PermissionEnum::ContactsUpdate,
                PermissionEnum::ContactsDelete,
                PermissionEnum::DealsView,
                PermissionEnum::DealsCreate,
                PermissionEnum::DealsUpdate,
                PermissionEnum::DealsDelete,
                PermissionEnum::DealsMoveStage,
                PermissionEnum::PipelinesView,
                PermissionEnum::CustomFieldsView,
            ],

            RoleEnum::AccountManager->value => [
                PermissionEnum::LeadsView,
                PermissionEnum::LeadsCreate,
                PermissionEnum::LeadsUpdate,
                PermissionEnum::LeadsConvert,
                PermissionEnum::CompaniesView,
                PermissionEnum::CompaniesUpdate,
                PermissionEnum::ContactsView,
                PermissionEnum::ContactsCreate,
                PermissionEnum::ContactsUpdate,
                PermissionEnum::DealsView,
                PermissionEnum::DealsCreate,
                PermissionEnum::DealsUpdate,
                PermissionEnum::DealsMoveStage,
                PermissionEnum::PipelinesView,
                PermissionEnum::CustomFieldsView,
            ],

            RoleEnum::ProjectManager->value => [
                PermissionEnum::LeadsView,
                PermissionEnum::CompaniesView,
                PermissionEnum::ContactsView,
                PermissionEnum::DealsView,
                PermissionEnum::PipelinesView,
                PermissionEnum::CustomFieldsView,
            ],

            RoleEnum::Support->value => [
                PermissionEnum::LeadsView,
                PermissionEnum::CompaniesView,
                PermissionEnum::ContactsView,
                PermissionEnum::DealsView,
                PermissionEnum::PipelinesView,
                PermissionEnum::CustomFieldsView,
            ],

            RoleEnum::It->value => [
                PermissionEnum::LeadsView,
                PermissionEnum::CompaniesView,
                PermissionEnum::ContactsView,
                PermissionEnum::PipelinesView,
                PermissionEnum::CustomFieldsView,
                PermissionEnum::AdminSystemHealth,
            ],
        ];
    }
}
