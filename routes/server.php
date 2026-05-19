<?php

use App\Http\Controllers\ServerController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web'])->group(function () {
    // Public routes
    Route::get('/login', [ServerController::class, 'showLogin'])->name('server.login_view');
    Route::get('/dashboard', [ServerController::class, 'showDashboard'])->name('server.dashboard');
    Route::post('/logout', [ServerController::class, 'logout'])->name('server.logout');

    // API Routes
    Route::post('/register', [ServerController::class, 'register'])->name('server.register');
    Route::post('/login', [ServerController::class, 'login'])->name('server.login');

    // Google Auth routes only
    Route::get('/google', [ServerController::class, 'redirectToGoogle'])->name('server.google.redirect');
    Route::get('/google/callback', [ServerController::class, 'handleGoogleCallback'])->name('server.google.callback');
});
