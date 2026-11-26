<?php

use App\Modules\Crm\Notes\Http\Controllers\NoteController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'active.employee'])->group(function (): void {
    Route::post('/api/internal/crm/notes', [NoteController::class, 'store'])
        ->name('crm.notes.store');

    Route::patch('/api/internal/crm/notes/{note}', [NoteController::class, 'update'])
        ->whereUuid('note')
        ->name('crm.notes.update');

    Route::delete('/api/internal/crm/notes/{note}', [NoteController::class, 'destroy'])
        ->whereUuid('note')
        ->name('crm.notes.destroy');

    Route::patch('/api/internal/crm/notes/{note}/pin', [NoteController::class, 'togglePin'])
        ->whereUuid('note')
        ->name('crm.notes.pin');
});
