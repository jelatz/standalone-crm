<?php

use App\Modules\Projects\Templates\Http\Controllers\ProjectTemplateController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'active.employee'])->group(function (): void {
    Route::get('/api/internal/project-templates', [ProjectTemplateController::class, 'index'])
        ->name('projects.templates.index');
});
