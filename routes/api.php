<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BarangayController;
use App\Http\Controllers\Api\FloodEventController;
use App\Http\Controllers\Api\FloodImpactReportController;
use App\Http\Controllers\Api\FloodReportController;
use App\Http\Controllers\Api\GeocodeController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\RealtimeController;
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
    ->middleware(['auth:sanctum', 'throttle:auth-status'])
    ->name('verification.notice');

Route::middleware(['auth:sanctum', 'db.identity', 'throttle:auth-status'])->group(function () {
    Route::get('/user', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::post('/email/verification-notification', [AuthController::class, 'resendVerification'])
        ->middleware('throttle:6,1')
        ->name('verification.send');
});

Route::middleware(['auth:sanctum', 'db.identity', 'verified', 'throttle:auth-status'])->group(function () {
    Route::get('/flood-reports', [FloodReportController::class, 'index']);
    Route::get('/flood-reports/deadlines', [FloodReportController::class, 'deadlines'])
        ->middleware(['role:mdrrmo_officer,mdrrmo_staff', 'throttle:30,1']);
    Route::get('/flood-reports/validated-feed', [FloodReportController::class, 'validatedFeed'])
        ->middleware(['role:mdrrmo_officer,mdrrmo_staff,rescue_leader', 'throttle:60,1']);
    Route::post('/flood-reports', [FloodReportController::class, 'store'])
        ->middleware('throttle:10,1');
    Route::get('/flood-reports/{floodReport}', [FloodReportController::class, 'show']);

    Route::get('/flood-events', [FloodEventController::class, 'index']);

    Route::get('/flood-impact-reports', [FloodImpactReportController::class, 'index']);
    Route::post('/flood-impact-reports', [FloodImpactReportController::class, 'store'])
        ->middleware('throttle:10,1');

    Route::get('/geocode/reverse', [GeocodeController::class, 'reverse'])
        ->middleware('throttle:30,1');

    Route::middleware('throttle:tokens')->group(function () {
        Route::get('/tokens', [TokenController::class, 'index']);
        Route::post('/tokens', [TokenController::class, 'store']);
        Route::delete('/tokens', [TokenController::class, 'destroyAll']);
        Route::delete('/tokens/{token}', [TokenController::class, 'destroy']);
    });

    Route::get('/realtime/authorization', [RealtimeController::class, 'authorization'])
        ->middleware('throttle:30,1');
    Route::post('/realtime/latency', [RealtimeController::class, 'recordLatency'])
        ->middleware('throttle:60,1');
});

Route::middleware(['auth:sanctum', 'db.identity', 'verified', 'role:mdrrmo_officer,mdrrmo_staff', 'throttle:auth-status'])->group(function () {
    Route::post('/flood-reports/{floodReport}/validate', [FloodReportController::class, 'validateReport']);
    Route::post('/flood-reports/{floodReport}/return', [FloodReportController::class, 'returnReport']);

    Route::get('/barangays', [BarangayController::class, 'index'])
        ->middleware('throttle:admin-write');
    Route::get('/realtime/latency', [RealtimeController::class, 'latencySummary']);
});

Route::middleware(['auth:sanctum', 'db.identity', 'verified', 'role:mdrrmo_officer', 'throttle:admin-write'])->group(function () {
    Route::post('/flood-reports/{floodReport}/reject', [FloodReportController::class, 'reject']);
    Route::post('/flood-reports/{floodReport}/override', [FloodReportController::class, 'overrideReport']);

    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::patch('/users/{user}', [UserController::class, 'update']);
    Route::delete('/users/{user}', [UserController::class, 'destroy']);
});
