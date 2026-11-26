<?php

namespace App\Modules\Crm\Timeline\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum ActivityTypeEnum: string
{
    case NoteCreated = 'note_created';
    case LeadCreated = 'lead_created';
    case LeadConverted = 'lead_converted';
    case DealCreated = 'deal_created';
    case StageChanged = 'stage_changed';
    case FieldUpdated = 'field_updated';
}
