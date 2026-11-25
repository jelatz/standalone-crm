<?php

namespace App\Modules\Crm\CustomFields\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum CustomFieldTypeEnum: string
{
    case Text = 'text';
    case Textarea = 'textarea';
    case Number = 'number';
    case Currency = 'currency';
    case Boolean = 'boolean';
    case Date = 'date';
    case Select = 'select';
    case MultiSelect = 'multi_select';
    case UserId = 'user_id';
    case Url = 'url';
}
