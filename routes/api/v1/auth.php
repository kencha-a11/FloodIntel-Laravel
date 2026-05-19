<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\SocialAuthController;
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

    // 1. Public Native Auth Routes (URL: api/v1/auth/register & login)
    Route::post('/register', [AuthController::class, 'register'])->name('auth.v1.register');
    Route::post('/login', [AuthController::class, 'login'])->name('auth.v1.login');

    // 2. Protected Native Auth Routes (URL: api/v1/auth/logout)
    // Route::middleware('auth:sanctum')->group(function () {
    //     Route::post('/logout', [AuthController::class, 'logout'])->name('auth.v1.logout');
    // });

    // 3. Socialite OAuth Routes (URL: api/v1/auth/{provider} & callback)
    Route::get('/{provider}', [SocialAuthController::class, 'redirectToProvider'])
        ->where('provider', 'google')
        ->name('auth.v1.social.redirect');

    Route::get('/{provider}/callback', [SocialAuthController::class, 'handleProviderCallback'])
        ->where('provider', 'google')
        ->name('auth.v1.social.callback');

});
