<?php

use App\Modules\Crm\Leads\Http\Controllers\LeadController;
use App\Modules\Crm\Leads\Http\Controllers\LeadImportController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'active.employee'])->group(function (): void {
    Route::get('/app/crm/leads', [LeadController::class, 'index'])
        ->name('crm.leads');

    Route::get('/api/internal/crm/leads/import/template', [LeadImportController::class, 'template'])
        ->name('crm.leads.import.template');

    Route::post('/api/internal/crm/leads/import/preview', [LeadImportController::class, 'preview'])
        ->middleware('throttle:20,1')
        ->name('crm.leads.import.preview');

    Route::post('/api/internal/crm/leads/import/commit', [LeadImportController::class, 'commit'])
        ->middleware('throttle:20,1')
        ->name('crm.leads.import.commit');

    Route::get('/app/crm/leads/{lead}', [LeadController::class, 'show'])
        ->whereUuid('lead')
        ->name('crm.leads.show');

    Route::post('/api/internal/crm/leads', [LeadController::class, 'store'])
        ->name('crm.leads.store');

    Route::patch('/api/internal/crm/leads/{lead}', [LeadController::class, 'update'])
        ->whereUuid('lead')
        ->name('crm.leads.update');

    Route::delete('/api/internal/crm/leads/{lead}', [LeadController::class, 'destroy'])
        ->whereUuid('lead')
        ->name('crm.leads.destroy');

    Route::post('/api/internal/crm/leads/{lead}/convert', [LeadController::class, 'convert'])
        ->whereUuid('lead')
        ->name('crm.leads.convert');

    Route::post('/api/internal/crm/leads/{lead}/collaborators', [LeadController::class, 'grantCollaborator'])
        ->whereUuid('lead')
        ->name('crm.leads.collaborators.store');

    Route::delete('/api/internal/crm/leads/{lead}/collaborators/{user}', [LeadController::class, 'revokeCollaborator'])
        ->whereUuid(['lead', 'user'])
        ->name('crm.leads.collaborators.destroy');
});
