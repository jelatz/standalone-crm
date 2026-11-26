<?php

use App\Modules\Crm\Companies\Http\Controllers\CompanyController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'active.employee'])->group(function (): void {
    Route::get('/app/crm/companies', [CompanyController::class, 'index'])
        ->name('crm.companies');

    Route::get('/app/crm/companies/{company}', [CompanyController::class, 'show'])
        ->whereUuid('company')
        ->name('crm.companies.show');

    Route::post('/api/internal/crm/companies', [CompanyController::class, 'store'])
        ->name('crm.companies.store');

    Route::patch('/api/internal/crm/companies/{company}', [CompanyController::class, 'update'])
        ->whereUuid('company')
        ->name('crm.companies.update');

    Route::delete('/api/internal/crm/companies/{company}', [CompanyController::class, 'destroy'])
        ->whereUuid('company')
        ->name('crm.companies.destroy');

    Route::post('/api/internal/crm/companies/{company}/collaborators', [CompanyController::class, 'grantCollaborator'])
        ->whereUuid('company')
        ->name('crm.companies.collaborators.store');

    Route::delete('/api/internal/crm/companies/{company}/collaborators/{user}', [CompanyController::class, 'revokeCollaborator'])
        ->whereUuid(['company', 'user'])
        ->name('crm.companies.collaborators.destroy');
});
