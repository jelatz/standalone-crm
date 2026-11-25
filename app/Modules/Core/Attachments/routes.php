<?php

use App\Modules\Core\Attachments\Http\Controllers\AttachmentController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'active.employee'])->group(function (): void {
    Route::post('/api/internal/attachments/presign', [AttachmentController::class, 'presign'])
        ->name('attachments.presign');

    Route::get('/attachments/{attachment}/download', [AttachmentController::class, 'download'])
        ->whereUuid('attachment')
        ->name('attachments.download');
});
