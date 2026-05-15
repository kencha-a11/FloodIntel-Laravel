<?php

use App\Http\Controllers\Api\V1\AuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Auth Routes - Version 1
|--------------------------------------------------------------------------
|
| Ang mga routes na ito ay may prefix na 'api/v1/auth' base sa ating
| main routes/api.php configuration.
|
*/

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])->name('auth.v1.register');

    Route::post('/login', [AuthController::class, 'login'])->name('auth.v1.login');

    Route::middleware('auth:sanctum')->group(function () {
    });
    Route::post('/logout', [AuthController::class, 'logout'])->name('auth.v1.logout');
});

// Features: Google Socialite: Facebook, Google accounts
