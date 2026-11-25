<?php

namespace App\Modules\Crm\Timeline\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum ActivityTypeEnum: string
{
    case NoteCreated = 'note_created';
    case DealCreated = 'deal_created';
    case StageChanged = 'stage_changed';
    case FieldUpdated = 'field_updated';
    case TaskCreated = 'task_created';
    case TaskCompleted = 'task_completed';
    case EmailReceived = 'email_received';
    case EmailSent = 'email_sent';
    case ChatThreadCreated = 'chat_thread_created';
    case HandoffSubmitted = 'handoff_submitted';
    case HandoffApproved = 'handoff_approved';
    case ProjectProvisioned = 'project_provisioned';
}
