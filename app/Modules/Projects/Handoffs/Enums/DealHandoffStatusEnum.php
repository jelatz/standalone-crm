<?php

namespace App\Modules\Projects\Handoffs\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum DealHandoffStatusEnum: string
{
    case PendingReview = 'pending_review';
    case ChangesRequested = 'changes_requested';
    case Approved = 'approved';
    case Provisioned = 'provisioned';
    case Rejected = 'rejected';
}
