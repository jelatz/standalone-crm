<?php

namespace App\Modules\Crm\Companies\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum CompanyLifecycleStageEnum: string
{
    case Lead = 'lead';
    case Prospect = 'prospect';
    case ActiveClient = 'active_client';
    case Churned = 'churned';
    case Partner = 'partner';
}
