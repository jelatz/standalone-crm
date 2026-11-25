<?php

namespace App\Modules\Core\Users\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum UserStatusEnum: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Offboarded = 'offboarded';
}
