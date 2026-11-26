<?php

namespace App\Modules\Crm\CustomFields\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum CustomFieldEntityTypeEnum: string
{
    case Lead = 'lead';
    case Company = 'company';
    case Contact = 'contact';
    case Deal = 'deal';
}
