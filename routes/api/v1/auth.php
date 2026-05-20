<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\SocialAuthController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\Auth\TermsConditionController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController; // Idinagdag ito
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {

    // 1. AuthController (Registration & Login)
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::middleware('auth:sanctum')->post('/logout', [AuthController::class, 'logout']);

    // 2. SocialAuthController (Google)
    Route::get('/{provider}', [SocialAuthController::class, 'redirectToProvider'])
        ->where('provider', 'google');
    Route::get('/{provider}/callback', [SocialAuthController::class, 'handleProviderCallback'])
        ->where('provider', 'google');

    // 3. PasswordResetController (Forgot & Reset Password)
    Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink']);
    Route::post('/reset-password', [PasswordResetController::class, 'resetPassword']);

    // 4. EmailVerificationController (Resend & Verify)
    Route::middleware('auth:sanctum')->post('/email/resend-verification', [EmailVerificationController::class, 'sendVerificationNotification']);
    // Tandaan: Ang verify route ay madalas binubuksan via browser/email client
    Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verifyEmail'])
        ->name('verification.verify');

    // 5. TermsConditionController (Protected)
    Route::middleware('auth:sanctum')->post('/terms/accept', [TermsConditionController::class, 'acceptTerms']);
});
