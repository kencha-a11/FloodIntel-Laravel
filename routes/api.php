<?php

use Illuminate\Support\Facades\Route;

// Isang beses lang dapat ang definition
Route::prefix('v1')->group(function () {
    // Dito papasok ang /login, /register, at /logout
    require __DIR__ . '/api/v1/auth.php';

    // require __DIR__ .'/api/v1/tanod.php';
});
