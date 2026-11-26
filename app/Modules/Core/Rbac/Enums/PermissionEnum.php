<?php

namespace App\Modules\Core\Rbac\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum PermissionEnum: string
{
    case LeadsView = 'leads.view';
    case LeadsCreate = 'leads.create';
    case LeadsUpdate = 'leads.update';
    case LeadsDelete = 'leads.delete';
    case LeadsConvert = 'leads.convert';

    case CompaniesView = 'companies.view';
    case CompaniesCreate = 'companies.create';
    case CompaniesUpdate = 'companies.update';
    case CompaniesDelete = 'companies.delete';

    case ContactsView = 'contacts.view';
    case ContactsCreate = 'contacts.create';
    case ContactsUpdate = 'contacts.update';
    case ContactsDelete = 'contacts.delete';

    case DealsView = 'deals.view';
    case DealsCreate = 'deals.create';
    case DealsUpdate = 'deals.update';
    case DealsDelete = 'deals.delete';
    case DealsMoveStage = 'deals.move_stage';
    case DealsOverrideGates = 'deals.override_gates';

    case PipelinesView = 'pipelines.view';
    case PipelinesManage = 'pipelines.manage';

    case CustomFieldsView = 'custom_fields.view';
    case CustomFieldsManage = 'custom_fields.manage';

    case AdminUsers = 'admin.users';
    case AdminSystemHealth = 'admin.system_health';
}
