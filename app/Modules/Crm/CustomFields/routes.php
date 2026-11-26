<?php

use App\Modules\Crm\CustomFields\Http\Controllers\CustomFieldController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'active.employee'])->group(function (): void {
    Route::post('/api/internal/crm/custom-fields', [CustomFieldController::class, 'store'])
        ->name('crm.custom-fields.store');

    Route::patch('/api/internal/crm/custom-fields/{customFieldDefinition}', [CustomFieldController::class, 'update'])
        ->whereUuid('customFieldDefinition')
        ->name('crm.custom-fields.update');

    Route::delete('/api/internal/crm/custom-fields/{customFieldDefinition}', [CustomFieldController::class, 'destroy'])
        ->whereUuid('customFieldDefinition')
        ->name('crm.custom-fields.destroy');
});
