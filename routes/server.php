<?php

use Illuminate\Foundation\Auth\EmailVerificationRequest;
use App\Http\Controllers\ServerController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web'])->group(function () {
    // Google Auth routes only
    Route::get('/google', [ServerController::class, 'redirectToGoogle'])->name('server.google.redirect');
    Route::get('/google/callback', [ServerController::class, 'handleGoogleCallback'])->name('server.google.callback');
    // Route para kapag nag-click sila sa email verification link
    Route::get('/email/verify/{id}/{hash}', function (EmailVerificationRequest $request) {
        $request->fulfill();
        return redirect('/dashboard');
    })->middleware(['auth', 'signed'])->name('verification.verify');
    Route::get('/email/verify', function () {
        return view('auth.verify-email');
    })->middleware('auth')->name('verification.notice');

    // Public routes
    Route::get('/login', [ServerController::class, 'showLogin'])->name('server.login_view');
    // Protektadong route
    Route::get('/dashboard', [ServerController::class, 'showDashboard'])
        ->middleware(['auth', 'verified'])
        ->name('server.dashboard');
    Route::post('/logout', [ServerController::class, 'logout'])->name('server.logout');

    // API Routes
    Route::post('/register', [ServerController::class, 'register'])->name('server.register');
    Route::post('/login', [ServerController::class, 'login'])->name('server.login');



});
