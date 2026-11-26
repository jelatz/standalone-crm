<?php

namespace App\Modules\Crm\Companies\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum CompanyLifecycleStageEnum: string
{
    case Prospect = 'prospect';
    case ActiveClient = 'active_client';
    case Churned = 'churned';
    case Partner = 'partner';
}
