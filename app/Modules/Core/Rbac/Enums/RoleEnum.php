<?php

namespace App\Modules\Core\Rbac\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum RoleEnum: string
{
    case Superadmin = 'superadmin';
    case Admin = 'admin';
    case Director = 'director';
    case OperationsManager = 'operations_manager';
    case Sales = 'sales';
    case AccountManager = 'account_manager';
    case ProjectManager = 'project_manager';
    case Support = 'support';
    case It = 'it';
}
