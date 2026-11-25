<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('auth')->group(function (): void {
    Route::get('/app', fn () => Inertia::render('core/pages/WorkspaceHomePage'))->name('app.home');
});
