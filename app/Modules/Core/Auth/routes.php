<?php

use App\Modules\Core\Auth\Http\Controllers\SsoController;
use Illuminate\Support\Facades\Route;

Route::get('/login', [SsoController::class, 'showLogin'])->name('login');
Route::get('/auth/{provider}/redirect', [SsoController::class, 'redirect'])->name('auth.sso.redirect');
Route::get('/auth/{provider}/callback', [SsoController::class, 'callback'])->name('auth.sso.callback');
Route::post('/logout', [SsoController::class, 'logout'])->name('logout');
