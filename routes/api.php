<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BarangayController;
use App\Http\Controllers\Api\FloodReportController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\TokenController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink'])
    ->middleware('throttle:6,1')
    ->name('password.email');

Route::post('/reset-password', [PasswordResetController::class, 'reset'])
    ->middleware('throttle:6,1')
    ->name('password.store');

Route::get('/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
    ->middleware(['signed', 'throttle:6,1'])
    ->name('verification.verify');

Route::get('/email/verify', [AuthController::class, 'verificationNotice'])
    ->middleware('auth:sanctum')
    ->name('verification.notice');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::post('/email/verification-notification', [AuthController::class, 'resendVerification'])
        ->middleware('throttle:6,1')
        ->name('verification.send');
});

Route::middleware(['auth:sanctum', 'verified'])->group(function () {
    Route::get('/flood-reports', [FloodReportController::class, 'index']);
    Route::post('/flood-reports', [FloodReportController::class, 'store'])
        ->middleware('throttle:10,1');
    Route::get('/flood-reports/{floodReport}', [FloodReportController::class, 'show']);

    Route::get('/tokens', [TokenController::class, 'index']);
    Route::post('/tokens', [TokenController::class, 'store']);
    Route::delete('/tokens', [TokenController::class, 'destroyAll']);
    Route::delete('/tokens/{token}', [TokenController::class, 'destroy']);
});

Route::middleware(['auth:sanctum', 'verified', 'role:mdrrmo_officer,mdrrmo_staff'])->group(function () {
    Route::get('/barangays', [BarangayController::class, 'index']);
});

Route::middleware(['auth:sanctum', 'verified', 'role:mdrrmo_officer'])->group(function () {
    Route::post('/flood-reports/{floodReport}/reject', [FloodReportController::class, 'reject']);

    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::patch('/users/{user}', [UserController::class, 'update']);
    Route::delete('/users/{user}', [UserController::class, 'destroy']);
});
