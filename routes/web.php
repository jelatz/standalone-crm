<?php

use Illuminate\Support\Facades\Route;

Route::redirect('/', '/app')->name('root');

Route::middleware(['auth', 'active.employee'])->prefix('app')->group(function (): void {
    Route::inertia('/', 'core/pages/WorkspaceHomePage')
        ->name('app.home');

    Route::inertia('/chat', 'core/pages/ModulePlaceholderPage', [
        'moduleKey' => 'chat.index',
        'moduleTitle' => 'Team Chat',
        'moduleDescription' => 'Internal channels, DMs, and context-linked Deal/Project threads (Phase 3).',
        'phaseLabel' => 'Phase 3',
    ])->name('chat.index');

    Route::inertia('/email', 'core/pages/ModulePlaceholderPage', [
        'moduleKey' => 'email.index',
        'moduleTitle' => 'Shared & Personal Inbox',
        'moduleDescription' => 'Shared and personal inbox triage queues and RFC 5322 threads (Phase 4).',
        'phaseLabel' => 'Phase 4',
    ])->name('email.index');
});
