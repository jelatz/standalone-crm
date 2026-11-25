<?php

namespace App\Modules\Core\Rbac\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum CollaboratorAccessLevel: string
{
    case Viewer = 'viewer';
    case Editor = 'editor';
    case Manager = 'manager';
}
