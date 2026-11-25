<?php

namespace App\Modules\Crm\Deals\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum DealStakeholderRoleEnum: string
{
    case DecisionMaker = 'decision_maker';
    case Champion = 'champion';
    case Billing = 'billing';
    case TechnicalBuyer = 'technical_buyer';
    case Influencer = 'influencer';
}
