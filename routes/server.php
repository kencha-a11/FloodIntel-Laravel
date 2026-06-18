<?php
// server.php

use Illuminate\Foundation\Auth\EmailVerificationRequest;
use App\Http\Controllers\ServerController;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;

// Route::middleware(['web'])->group(function () {

//     // 1. GUEST ROUTES: Para sa mga users na hindi pa naka-login
//     Route::middleware('guest')->group(function () {
//         Route::get('/login', [ServerController::class, 'showLogin'])->name('server.login');
//         Route::post('/login', [ServerController::class, 'login'])->name('server.login');
//         Route::post('/register', [ServerController::class, 'register'])->name('server.register');
//         Route::get('/google', [ServerController::class, 'redirectToGoogle'])->name('server.google.redirect');

//         Route::get('/forgot-password', function () {
//             return view('server.forgot-password');
//         })->name('server.password.request');
//         Route::post('/forgot-password', [ServerController::class, 'sendResetLink'])->name('server.password.email');
//         Route::get('/reset-password/{token}', function ($token) {
//             return view('server.reset-password', ['token' => $token]);
//         })->name('password.reset'); // [VITAL]
//         Route::post('/reset-password', [ServerController::class, 'resetPassword'])->name('server.password.update');
//     });

//     // 2. CALLBACK ROUTE: Labas sa 'guest'
//     Route::get('/google/callback', [ServerController::class, 'handleGoogleCallback'])->name('server.google.callback');

//     // 3. AUTH ROUTES: Kailangan login na ang user (pero pwedeng hindi pa verified)
//     Route::middleware('auth')->group(function () {
//         Route::post('/logout', [ServerController::class, 'logout'])->name('server.logout');

//         Route::get('/email/verify', function () {
//             return view('server.verify-email');
//         })->name('server.verification.notice');

//         Route::post('/email/verification-notification', function (Request $request) {
//             $user = $request->user();

//             if ($user->hasVerifiedEmail()) {
//                 return back()->with('status', 'Email already verified.');
//             }

//             $user->sendEmailVerificationNotification();
//             return back()->with('status', 'Verification link sent!');
//         })->middleware('throttle:6,1')->name('server.verification.send');
//     });

//     // Email Verification Handler (dapat signed route)
//     Route::get('/email/verify/{id}/{hash}', function (EmailVerificationRequest $request) {
//         $request->fulfill();
//         return redirect()->route('server.dashboard');
//     })->middleware(['auth', 'signed'])->name('verification.verify'); // [VITAL]

//     // 4. PROTECTED ROUTES: Auth + Verified + Terms Accepted
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
