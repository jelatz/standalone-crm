<?php

namespace App\Modules\Crm\Leads\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum LeadSourceEnum: string
{
    case InboundWeb = 'inbound_web';
    case Outbound = 'outbound';
    case Referral = 'referral';
    case Event = 'event';
    case Partner = 'partner';
    case Other = 'other';
}
