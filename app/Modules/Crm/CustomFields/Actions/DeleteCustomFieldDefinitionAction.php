<?php

namespace App\Modules\Crm\CustomFields\Actions;

use App\Modules\Crm\CustomFields\Models\CustomFieldDefinition;
use Illuminate\Support\Facades\DB;

class DeleteCustomFieldDefinitionAction
{
    public function execute(CustomFieldDefinition $definition): void
    {
        DB::transaction(function () use ($definition): void {
            $definition->delete();
        });
    }
}
