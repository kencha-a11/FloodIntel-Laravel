<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use Illuminate\Support\Facades\Route;


// GAWING LITERALLY 'login' ANG PANGALAN NG ROUTE NA ITO
Route::get('/login', function () {
    return view('auth.login');
})->name('login'); // 👈 Eto ang hinahanap ng core auth middleware kapag unauthenticated ka na

Route::get('/dashboard', function () {
    return view('dashboard');
})->name('dashboard');

// ILIPAT DITO: Para magkaroon siya ng automatic session support galing sa Web state pipeline
Route::post('logout', [AuthController::class, 'logout'])->name('logout');