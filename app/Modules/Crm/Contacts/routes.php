<?php

use App\Modules\Crm\Contacts\Http\Controllers\ContactController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'active.employee'])->group(function (): void {
    Route::get('/app/crm/contacts', [ContactController::class, 'index'])
        ->name('crm.contacts');

    Route::get('/app/crm/contacts/{contact}', [ContactController::class, 'show'])
        ->whereUuid('contact')
        ->name('crm.contacts.show');

    Route::post('/api/internal/crm/contacts', [ContactController::class, 'store'])
        ->name('crm.contacts.store');

    Route::patch('/api/internal/crm/contacts/{contact}', [ContactController::class, 'update'])
        ->whereUuid('contact')
        ->name('crm.contacts.update');

    Route::delete('/api/internal/crm/contacts/{contact}', [ContactController::class, 'destroy'])
        ->whereUuid('contact')
        ->name('crm.contacts.destroy');

    Route::post('/api/internal/crm/contacts/{contact}/collaborators', [ContactController::class, 'grantCollaborator'])
        ->whereUuid('contact')
        ->name('crm.contacts.collaborators.store');

    Route::delete('/api/internal/crm/contacts/{contact}/collaborators/{user}', [ContactController::class, 'revokeCollaborator'])
        ->whereUuid(['contact', 'user'])
        ->name('crm.contacts.collaborators.destroy');
});
