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
                PermissionEnum::HandoffsView,
                PermissionEnum::HandoffsSubmit,
                PermissionEnum::HandoffsReview,
                PermissionEnum::HandoffsApprove,
                PermissionEnum::HandoffsRequestInfo,
                PermissionEnum::ProjectsView,
                PermissionEnum::ProjectsCreate,
                PermissionEnum::ProjectsUpdate,
                PermissionEnum::ProjectsDelete,
                PermissionEnum::TasksView,
                PermissionEnum::TasksCreate,
                PermissionEnum::TasksUpdate,
                PermissionEnum::TasksDelete,
                PermissionEnum::TasksComment,
                PermissionEnum::MailboxesViewShared,
                PermissionEnum::MailboxesReplyShared,
                PermissionEnum::MailboxesAssignShared,
                PermissionEnum::MailboxesConnectPersonal,
                PermissionEnum::ChatView,
                PermissionEnum::ChatSend,
                PermissionEnum::ChatManageChannels,
            ],

            RoleEnum::OperationsManager->value => [
                PermissionEnum::CompaniesView,
                PermissionEnum::ContactsView,
                PermissionEnum::DealsView,
                PermissionEnum::PipelinesView,
                PermissionEnum::CustomFieldsView,
                PermissionEnum::HandoffsView,
                PermissionEnum::HandoffsReview,
                PermissionEnum::HandoffsApprove,
                PermissionEnum::HandoffsRequestInfo,
                PermissionEnum::ProjectsView,
                PermissionEnum::ProjectsCreate,
                PermissionEnum::ProjectsUpdate,
                PermissionEnum::ProjectsDelete,
                PermissionEnum::ProjectsManageTemplates,
                PermissionEnum::TasksView,
                PermissionEnum::TasksCreate,
                PermissionEnum::TasksUpdate,
                PermissionEnum::TasksDelete,
                PermissionEnum::TasksComment,
                PermissionEnum::MailboxesViewShared,
                PermissionEnum::MailboxesReplyShared,
                PermissionEnum::MailboxesAssignShared,
                PermissionEnum::MailboxesConnectPersonal,
                PermissionEnum::ChatView,
                PermissionEnum::ChatSend,
                PermissionEnum::ChatManageChannels,
            ],

            RoleEnum::Sales->value => [
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
                PermissionEnum::HandoffsView,
                PermissionEnum::HandoffsSubmit,
                PermissionEnum::ProjectsView,
                PermissionEnum::TasksView,
                PermissionEnum::TasksComment,
                PermissionEnum::MailboxesViewShared,
                PermissionEnum::MailboxesReplyShared,
                PermissionEnum::MailboxesConnectPersonal,
                PermissionEnum::ChatView,
                PermissionEnum::ChatSend,
            ],

            RoleEnum::AccountManager->value => [
                PermissionEnum::CompaniesView,
                PermissionEnum::CompaniesUpdate,
                PermissionEnum::ContactsView,
                PermissionEnum::ContactsUpdate,
                PermissionEnum::DealsView,
                PermissionEnum::DealsCreate,
                PermissionEnum::DealsUpdate,
                PermissionEnum::DealsMoveStage,
                PermissionEnum::PipelinesView,
                PermissionEnum::CustomFieldsView,
                PermissionEnum::HandoffsView,
                PermissionEnum::ProjectsView,
                PermissionEnum::TasksView,
                PermissionEnum::TasksComment,
                PermissionEnum::MailboxesViewShared,
                PermissionEnum::MailboxesReplyShared,
                PermissionEnum::MailboxesConnectPersonal,
                PermissionEnum::ChatView,
                PermissionEnum::ChatSend,
            ],

            RoleEnum::ProjectManager->value => [
                PermissionEnum::CompaniesView,
                PermissionEnum::ContactsView,
                PermissionEnum::DealsView,
                PermissionEnum::PipelinesView,
                PermissionEnum::CustomFieldsView,
                PermissionEnum::HandoffsView,
                PermissionEnum::HandoffsRequestInfo,
                PermissionEnum::ProjectsView,
                PermissionEnum::ProjectsCreate,
                PermissionEnum::ProjectsUpdate,
                PermissionEnum::ProjectsDelete,
                PermissionEnum::TasksView,
                PermissionEnum::TasksCreate,
                PermissionEnum::TasksUpdate,
                PermissionEnum::TasksDelete,
                PermissionEnum::TasksComment,
                PermissionEnum::MailboxesConnectPersonal,
                PermissionEnum::ChatView,
                PermissionEnum::ChatSend,
                PermissionEnum::ChatManageChannels,
            ],

            RoleEnum::Support->value => [
                PermissionEnum::CompaniesView,
                PermissionEnum::ContactsView,
                PermissionEnum::DealsView,
                PermissionEnum::PipelinesView,
                PermissionEnum::CustomFieldsView,
                PermissionEnum::ProjectsView,
                PermissionEnum::TasksView,
                PermissionEnum::TasksCreate,
                PermissionEnum::TasksUpdate,
                PermissionEnum::TasksDelete,
                PermissionEnum::TasksComment,
                PermissionEnum::MailboxesViewShared,
                PermissionEnum::MailboxesReplyShared,
                PermissionEnum::MailboxesAssignShared,
                PermissionEnum::MailboxesConnectPersonal,
                PermissionEnum::ChatView,
                PermissionEnum::ChatSend,
            ],

            RoleEnum::It->value => [
                PermissionEnum::CompaniesView,
                PermissionEnum::ContactsView,
                PermissionEnum::PipelinesView,
                PermissionEnum::CustomFieldsView,
                PermissionEnum::ProjectsView,
                PermissionEnum::ProjectsCreate,
                PermissionEnum::ProjectsUpdate,
                PermissionEnum::TasksView,
                PermissionEnum::TasksCreate,
                PermissionEnum::TasksUpdate,
                PermissionEnum::TasksDelete,
                PermissionEnum::TasksComment,
                PermissionEnum::MailboxesViewShared,
                PermissionEnum::MailboxesReplyShared,
                PermissionEnum::MailboxesAssignShared,
                PermissionEnum::MailboxesConnectPersonal,
                PermissionEnum::ChatView,
                PermissionEnum::ChatSend,
                PermissionEnum::AdminSystemHealth,
            ],
        ];
    }
}
