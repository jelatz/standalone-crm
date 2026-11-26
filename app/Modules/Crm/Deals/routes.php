<?php

use App\Modules\Crm\Deals\Http\Controllers\DealController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'active.employee'])->group(function (): void {
    Route::get('/app/crm/deals', [DealController::class, 'index'])
        ->name('crm.deals');

    Route::get('/app/crm/deals/{deal}', [DealController::class, 'show'])
        ->whereUuid('deal')
        ->name('crm.deals.show');

    Route::post('/api/internal/crm/deals', [DealController::class, 'store'])
        ->name('crm.deals.store');

    Route::patch('/api/internal/crm/deals/{deal}', [DealController::class, 'update'])
        ->whereUuid('deal')
        ->name('crm.deals.update');

    Route::patch('/api/internal/crm/deals/{deal}/move-stage', [DealController::class, 'moveStage'])
        ->whereUuid('deal')
        ->name('crm.deals.move_stage');

    Route::delete('/api/internal/crm/deals/{deal}', [DealController::class, 'destroy'])
        ->whereUuid('deal')
        ->name('crm.deals.destroy');

    Route::put('/api/internal/crm/deals/{deal}/contacts', [DealController::class, 'syncContacts'])
        ->whereUuid('deal')
        ->name('crm.deals.contacts.sync');

    Route::post('/api/internal/crm/deals/{deal}/collaborators', [DealController::class, 'grantCollaborator'])
        ->whereUuid('deal')
        ->name('crm.deals.collaborators.store');

    Route::delete('/api/internal/crm/deals/{deal}/collaborators/{user}', [DealController::class, 'revokeCollaborator'])
        ->whereUuid(['deal', 'user'])
        ->name('crm.deals.collaborators.destroy');
});
