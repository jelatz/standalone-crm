<?php

use App\Modules\Core\Users\Http\Controllers\UserManagementController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'active.employee'])->prefix('app/admin')->group(function (): void {
    Route::get('/users', [UserManagementController::class, 'index'])
        ->name('admin.users.index');

    Route::post('/users', [UserManagementController::class, 'store'])
        ->name('admin.users.store');
});
