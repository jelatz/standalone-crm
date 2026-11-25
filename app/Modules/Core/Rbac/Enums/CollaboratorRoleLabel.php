<?php

namespace App\Modules\Core\Rbac\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum CollaboratorRoleLabel: string
{
    case AccountManager = 'account_manager';
    case ProjectManager = 'project_manager';
    case SalesOverlay = 'sales_overlay';
    case SupportLead = 'support_lead';
    case ItLead = 'it_lead';
    case Observer = 'observer';
}
