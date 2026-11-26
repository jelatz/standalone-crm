<?php

use App\Modules\Crm\Overview\Http\Controllers\CrmOverviewController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/app')->name('root');

Route::middleware(['auth', 'active.employee'])->prefix('app')->group(function (): void {
    Route::get('/', [CrmOverviewController::class, 'index'])
        ->name('app.home');
});
