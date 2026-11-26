<?php

use App\Modules\Projects\Tasks\Http\Controllers\TaskController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'active.employee'])->group(function (): void {
    Route::get('/app/my-work', [TaskController::class, 'myWork'])
        ->name('projects.my_work');

    Route::post('/api/internal/tasks', [TaskController::class, 'store'])
        ->name('projects.tasks.store');

    Route::patch('/api/internal/tasks/{task}', [TaskController::class, 'update'])
        ->whereUuid('task')
        ->name('projects.tasks.update');

    Route::patch('/api/internal/tasks/{task}/move', [TaskController::class, 'move'])
        ->whereUuid('task')
        ->name('projects.tasks.move');

    Route::delete('/api/internal/tasks/{task}', [TaskController::class, 'destroy'])
        ->whereUuid('task')
        ->name('projects.tasks.destroy');

    Route::post('/api/internal/tasks/{task}/watchers', [TaskController::class, 'toggleWatcher'])
        ->whereUuid('task')
        ->name('projects.tasks.watchers.toggle');
});
