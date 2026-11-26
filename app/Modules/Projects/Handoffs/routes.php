<?php

use App\Modules\Projects\Handoffs\Http\Controllers\DealHandoffController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'active.employee'])->group(function (): void {
    Route::get('/app/projects/handoffs', [DealHandoffController::class, 'index'])
        ->name('projects.handoffs');

    Route::get('/app/projects/handoffs/{handoff}', [DealHandoffController::class, 'show'])
        ->whereUuid('handoff')
        ->name('projects.handoffs.show');

    Route::post('/api/internal/crm/deals/{deal}/handoffs', [DealHandoffController::class, 'store'])
        ->whereUuid('deal')
        ->name('crm.deals.handoffs.store');

    Route::post('/api/internal/handoffs/{handoff}/resubmit', [DealHandoffController::class, 'resubmit'])
        ->whereUuid('handoff')
        ->name('projects.handoffs.resubmit');

    Route::post('/api/internal/handoffs/{handoff}/request-changes', [DealHandoffController::class, 'requestChanges'])
        ->whereUuid('handoff')
        ->name('projects.handoffs.request_changes');

    Route::post('/api/internal/handoffs/{handoff}/reject', [DealHandoffController::class, 'reject'])
        ->whereUuid('handoff')
        ->name('projects.handoffs.reject');

    Route::post('/api/internal/handoffs/{handoff}/approve', [DealHandoffController::class, 'approve'])
        ->whereUuid('handoff')
        ->name('projects.handoffs.approve');
});
