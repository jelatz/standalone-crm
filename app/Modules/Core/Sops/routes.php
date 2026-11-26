<?php

use App\Modules\Core\Sops\Http\Controllers\SopManagementController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'active.employee'])->group(function (): void {
    Route::get('/app/admin/sops', [SopManagementController::class, 'index'])
        ->name('admin.sops.index');

    Route::get('/api/internal/admin/sops/{module}/{slug}', [SopManagementController::class, 'show'])
        ->where([
            'module' => '[a-z0-9]+(?:-[a-z0-9]+)*',
            'slug' => '[a-z0-9]+(?:-[a-z0-9]+)*',
        ])
        ->name('admin.sops.show');

    Route::post('/api/internal/admin/sops', [SopManagementController::class, 'store'])
        ->name('admin.sops.store');

    Route::put('/api/internal/admin/sops/{module}/{slug}', [SopManagementController::class, 'update'])
        ->where([
            'module' => '[a-z0-9]+(?:-[a-z0-9]+)*',
            'slug' => '[a-z0-9]+(?:-[a-z0-9]+)*',
        ])
        ->name('admin.sops.update');
});
