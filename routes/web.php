<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::inertia('/app', 'core/pages/WorkspaceHomePage')
    ->middleware('auth')
    ->name('app.home');
