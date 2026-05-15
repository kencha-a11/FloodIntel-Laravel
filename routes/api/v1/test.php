<?php

use App\Http\Controllers\Api\v1\BarangayController;
use Illuminate\Support\Facades\Route;

Route::prefix('test')->group(function () {
    Route::get('/simulation', function () {
        return view('simulation');
    })->name('test.simulation');

    // Gamitin ang class name para iwas typo
    Route::get('/flood-points', [BarangayController::class, 'getSpecificFloodPoints']);
});