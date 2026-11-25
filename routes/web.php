<?php

use Illuminate\Support\Facades\Route;

Route::redirect('/', '/app')->name('root');

Route::middleware(['auth', 'active.employee'])->prefix('app')->group(function (): void {
    Route::inertia('/', 'core/pages/WorkspaceHomePage')
        ->name('app.home');

    Route::inertia('/crm/companies', 'core/pages/ModulePlaceholderPage', [
        'moduleKey' => 'crm.companies',
        'moduleTitle' => 'Companies',
        'moduleDescription' => 'Silo-scoped enterprise accounts, lifecycle stages, and custom metadata (Phase 1).',
        'phaseLabel' => 'Phase 1',
    ])->name('crm.companies');

    Route::inertia('/crm/contacts', 'core/pages/ModulePlaceholderPage', [
        'moduleKey' => 'crm.contacts',
        'moduleTitle' => 'Contacts',
        'moduleDescription' => 'Stakeholder directory and multi-address email matching (Phase 1).',
        'phaseLabel' => 'Phase 1',
    ])->name('crm.contacts');

    Route::inertia('/crm/deals', 'core/pages/ModulePlaceholderPage', [
        'moduleKey' => 'crm.deals',
        'moduleTitle' => 'Deals Pipeline',
        'moduleDescription' => 'Multi-pipeline Kanban & table views with stage-gated custom fields (Phase 1).',
        'phaseLabel' => 'Phase 1',
    ])->name('crm.deals');

    Route::inertia('/projects/handoffs', 'core/pages/ModulePlaceholderPage', [
        'moduleKey' => 'projects.handoffs',
        'moduleTitle' => 'Handoff Intake',
        'moduleDescription' => 'Approval-gated Deal Won review queue for Operations & PM assignment (Phase 2).',
        'phaseLabel' => 'Phase 2',
    ])->name('projects.handoffs');

    Route::inertia('/projects', 'core/pages/ModulePlaceholderPage', [
        'moduleKey' => 'projects.index',
        'moduleTitle' => 'Projects & Tasks',
        'moduleDescription' => 'Templated delivery boards, task lists, and My Work assignments (Phase 2).',
        'phaseLabel' => 'Phase 2',
    ])->name('projects.index');

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
