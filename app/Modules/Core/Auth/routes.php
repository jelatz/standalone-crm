<?php

use App\Modules\Core\Auth\Http\Controllers\SsoController;
use Illuminate\Support\Facades\Route;

Route::get('/login', [SsoController::class, 'showLogin'])
    ->middleware('guest')
    ->name('login');
Route::get('/auth/{provider}/redirect', [SsoController::class, 'redirect'])
    ->middleware('throttle:20,1')
    ->name('auth.sso.redirect');
Route::get('/auth/{provider}/callback', [SsoController::class, 'callback'])
    ->middleware('throttle:20,1')
    ->name('auth.sso.callback');
Route::post('/logout', [SsoController::class, 'logout'])->name('logout');
