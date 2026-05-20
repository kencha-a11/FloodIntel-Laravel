<?php

use Illuminate\Foundation\Auth\EmailVerificationRequest;
use App\Http\Controllers\ServerController;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;

Route::middleware(['web'])->group(function () {

    // 1. GUEST ROUTES: Para sa mga users na hindi pa naka-login
    Route::middleware('guest')->group(function () {
        Route::get('/login', [ServerController::class, 'showLogin'])->name('login');
        Route::post('/login', [ServerController::class, 'login'])->name('server.login');
        Route::post('/register', [ServerController::class, 'register'])->name('server.register');

        // Google Auth Redirect
        Route::get('/google', [ServerController::class, 'redirectToGoogle'])->name('server.google.redirect');

        // --- PASSWORD RESET ROUTES (Bago) ---
        Route::get('/forgot-password', function () {
            return view('server.forgot-password');
        })->name('password.request');

        Route::post('/forgot-password', [ServerController::class, 'sendResetLink'])->name('password.email');

        Route::get('/reset-password/{token}', function ($token) {
            return view('server.reset-password', ['token' => $token]);
        })->name('password.reset');

        Route::post('/reset-password', [ServerController::class, 'resetPassword'])->name('password.update');
    });

    // 2. CALLBACK ROUTE: Labas sa 'guest' middleware para maiwasan ang loop
    Route::get('/google/callback', [ServerController::class, 'handleGoogleCallback'])->name('server.google.callback');

    // 3. AUTH ROUTES: Para sa mga users na naka-login na
    Route::middleware('auth')->group(function () {
        // Logout
        Route::post('/logout', [ServerController::class, 'logout'])->name('server.logout');

        // Email Verification Notice
        Route::get('/email/verify', function () {
            return view('server.verify-email');
        })->name('verification.notice');

        // Email Verification Logic
        Route::get('/email/verify/{id}/{hash}', function (EmailVerificationRequest $request) {
            $request->fulfill();
            return redirect()->route('server.dashboard');
        })->middleware('signed')->name('verification.verify');

        // Resend Verification Email
        Route::post('/email/verification-notification', function (Request $request) {
            $request->user()->sendEmailVerificationNotification();
            return back()->with('message', 'Verification link sent!');
        })->middleware('throttle:6,1')->name('verification.send');
    });

    // 4. PROTECTED ROUTES: Kailangan login + verified email
    Route::get('/dashboard', [ServerController::class, 'showDashboard'])
        ->middleware(['auth', 'verified'])
        ->name('server.dashboard');
});