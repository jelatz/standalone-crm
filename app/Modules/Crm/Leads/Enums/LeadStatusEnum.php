<?php

namespace App\Modules\Crm\Leads\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum LeadStatusEnum: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case Working = 'working';
    case Qualified = 'qualified';
    case Unqualified = 'unqualified';
    case Converted = 'converted';
}
