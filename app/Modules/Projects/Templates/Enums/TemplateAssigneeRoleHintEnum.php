<?php

namespace App\Modules\Projects\Templates\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum TemplateAssigneeRoleHintEnum: string
{
    case Pm = 'pm';
    case Am = 'am';
    case Support = 'support';
    case It = 'it';
}
