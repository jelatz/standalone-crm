<?php

namespace App\Modules\Core\Attachments\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum AttachmentScanStatus: string
{
    case Pending = 'pending';
    case Clean = 'clean';
    case Quarantined = 'quarantined';
}
