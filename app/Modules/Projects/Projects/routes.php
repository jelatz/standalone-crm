<?php

use App\Modules\Projects\Projects\Http\Controllers\ProjectController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'active.employee'])->group(function (): void {
    Route::get('/app/projects', [ProjectController::class, 'index'])
        ->name('projects.index');

    Route::get('/app/projects/{project}', [ProjectController::class, 'show'])
        ->whereUuid('project')
        ->name('projects.show');

    Route::post('/api/internal/projects', [ProjectController::class, 'store'])
        ->name('projects.store');

    Route::patch('/api/internal/projects/{project}', [ProjectController::class, 'update'])
        ->whereUuid('project')
        ->name('projects.update');

    Route::delete('/api/internal/projects/{project}', [ProjectController::class, 'destroy'])
        ->whereUuid('project')
        ->name('projects.destroy');

    Route::put('/api/internal/projects/{project}/stages', [ProjectController::class, 'manageStages'])
        ->whereUuid('project')
        ->name('projects.stages.update');

    Route::post('/api/internal/projects/{project}/collaborators', [ProjectController::class, 'grantCollaborator'])
        ->whereUuid('project')
        ->name('projects.collaborators.store');

    Route::delete('/api/internal/projects/{project}/collaborators/{user}', [ProjectController::class, 'revokeCollaborator'])
        ->whereUuid(['project', 'user'])
        ->name('projects.collaborators.destroy');
});
