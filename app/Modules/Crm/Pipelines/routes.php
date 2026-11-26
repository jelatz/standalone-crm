<?php

use App\Modules\Crm\Pipelines\Http\Controllers\PipelineController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'active.employee'])->group(function (): void {
    Route::get('/app/crm/settings/pipelines', [PipelineController::class, 'index'])
        ->name('crm.settings.pipelines');

    Route::post('/api/internal/crm/pipelines', [PipelineController::class, 'store'])
        ->name('crm.pipelines.store');

    Route::patch('/api/internal/crm/pipelines/{pipeline}', [PipelineController::class, 'update'])
        ->whereUuid('pipeline')
        ->name('crm.pipelines.update');

    Route::post('/api/internal/crm/pipelines/{pipeline}/stages', [PipelineController::class, 'storeStage'])
        ->whereUuid('pipeline')
        ->name('crm.pipelines.stages.store');

    Route::patch('/api/internal/crm/pipelines/{pipeline}/stages/{stage}', [PipelineController::class, 'updateStage'])
        ->whereUuid(['pipeline', 'stage'])
        ->scopeBindings()
        ->name('crm.pipelines.stages.update');

    Route::delete('/api/internal/crm/pipelines/{pipeline}/stages/{stage}', [PipelineController::class, 'destroyStage'])
        ->whereUuid(['pipeline', 'stage'])
        ->scopeBindings()
        ->name('crm.pipelines.stages.destroy');
});
