<?php

use App\Modules\Crm\Timeline\Http\Controllers\TimelineController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'active.employee'])->group(function (): void {
    Route::get('/api/internal/crm/timeline', [TimelineController::class, 'index'])
        ->name('crm.timeline.index');
});
