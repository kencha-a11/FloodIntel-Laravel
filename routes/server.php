<?php

use Illuminate\Foundation\Auth\EmailVerificationRequest;
use App\Http\Controllers\ServerController;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;

// Route::middleware(['web'])->group(function () {

//     // 1. GUEST ROUTES: Para sa mga users na hindi pa naka-login
//     Route::middleware('guest')->group(function () {
//         Route::get('/login', [ServerController::class, 'showLogin'])->name('login');
//         Route::post('/login', [ServerController::class, 'login'])->name('server.login');
//         Route::post('/register', [ServerController::class, 'register'])->name('server.register');
//         Route::get('/google', [ServerController::class, 'redirectToGoogle'])->name('server.google.redirect');

//         // Password Reset
//         Route::get('/forgot-password', function () {
//             return view('server.forgot-password');
//         })->name('password.request');
//         Route::post('/forgot-password', [ServerController::class, 'sendResetLink'])->name('password.email');
//         Route::get('/reset-password/{token}', function ($token) {
//             return view('server.reset-password', ['token' => $token]);
//         })->name('password.reset');
//         Route::post('/reset-password', [ServerController::class, 'resetPassword'])->name('password.update');
//     });

//     // 2. CALLBACK ROUTE: Labas sa 'guest'
//     Route::get('/google/callback', [ServerController::class, 'handleGoogleCallback'])->name('server.google.callback');

//     // 3. AUTH ROUTES: Kailangan login na ang user (pero pwedeng hindi pa verified)
//     Route::middleware('auth')->group(function () {
//         Route::post('/logout', [ServerController::class, 'logout'])->name('server.logout');

//         // Verification Routes
//         Route::get('/email/verify', function () {
//             return view('server.verify-email');
//         })->name('verification.notice');

//         Route::post('/email/verification-notification', function (Request $request) {
//             $request->user()->sendEmailVerificationNotification();
//             return back()->with('status', 'Verification link sent!');
//         })->middleware('throttle:6,1')->name('verification.send');
//     });

//     // Email Verification Handler (dapat signed route)
//     Route::get('/email/verify/{id}/{hash}', function (EmailVerificationRequest $request) {
//         $request->fulfill();
//         return redirect()->route('server.dashboard');
//     })->middleware(['auth', 'signed'])->name('verification.verify');

//     // 4. PROTECTED ROUTES: Auth + Verified + Terms Accepted
//     // Siguraduhin na may 'verified' at 'terms' middleware ka sa Kernel.php o app/Http/Kernel.php
//     Route::middleware(['auth', 'verified', 'terms'])->group(function () {
//         Route::get('/dashboard', [ServerController::class, 'showDashboard'])->name('server.dashboard');
//     });

//     Route::middleware(['auth'])->group(function () {
//         Route::get('/terms', function () {
//             return view('server.terms');
//         })->name('server.terms.show');
//         Route::post('/terms', [ServerController::class, 'acceptTerms'])->name('server.terms.accept');
//     });
// });
