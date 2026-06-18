<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\SocialAuthController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\Auth\TermsConditionController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;

Route::prefix('auth')->group(function () {

    // ==========================================
    // 1. GUEST ROUTES (no token)
    // ==========================================

    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);

    Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink']);
    Route::post('/reset-password', [PasswordResetController::class, 'resetPassword']);

    // VITAL: This route must be named 'password.reset' for Laravel's Password Broker to work
    Route::get('/password/reset/{token}', [PasswordResetController::class, 'redirectToResetForm'])
        ->name('password.reset');


    // ==========================================
    // 2. SOCIAL AUTH ROUTES (Google OAuth)
    // ==========================================

    Route::get('/{provider}', [SocialAuthController::class, 'redirectToProvider'])->where('provider', 'google');
    Route::get('/{provider}/callback', [SocialAuthController::class, 'handleProviderCallback'])->where('provider', 'google');


    // ==========================================
    // 3. AUTH ROUTES (Naka-login pero Pwedeng Unverified / No Terms)
    // ==========================================

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);

        Route::get('/user', function (Request $request) {
            return response()->json([
                'user' => $request->user(),
                'is_verified' => $request->user()->hasVerifiedEmail(),
                'has_accepted_terms' => !is_null($request->user()->terms_accepted_at),
            ]);
        });

        Route::post('/email/resend-verification', [EmailVerificationController::class, 'sendVerificationNotification']);

        Route::post('/terms/accept', [TermsConditionController::class, 'acceptTerms']);
    });


    // ==========================================
    // 4. EMAIL VERIFICATION HANDLER
    // ==========================================
    Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verifyEmail'])
        ->middleware(['signed'])
        ->name('verification.verify'); // [VITAL]
});


// ========================================================================
// 5. PROTECTED API ROUTES (Naka-login + Verified Email + Accepted Terms)
// ========================================================================
Route::middleware(['auth:sanctum', 'verified', 'terms'])->group(function () {

    Route::get('/dashboard', function (Request $request) {
        return response()->json([
            'message' => 'Welcome to the secure API dashboard!',
            'data' => 'Ito ang mga sikretong data na protektado ng middleware mo.'
        ]);
    });

});
