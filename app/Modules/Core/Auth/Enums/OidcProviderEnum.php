<?php

namespace App\Modules\Core\Auth\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum OidcProviderEnum: string
{
    case Google = 'google';
    case Microsoft = 'microsoft';
}
