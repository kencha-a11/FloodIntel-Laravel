<?php

use App\Http\Controllers\Api\v1\BarangayController;
use App\Http\Controllers\Api\v1\UserController;
use App\Http\Controllers\WebTestController;
use Illuminate\Support\Facades\Route;


Route::prefix('test')->group(function () {
    Route::get('/simulation', function () {
        return view('simulation');
    })->name('test.simulation');

    // Gamitin ang class name para iwas typo
    // Route::get('/flood-points', [BarangayController::class, 'getSpecificFloodPoints']);


    // gawa ka ng route para sa example data retrieval dito
    // Route::get('/users', [UserController::class, 'index']);

    Route::get('/todos', [WebTestController::class, 'index']);           // Read
    Route::post('/todos', [WebTestController::class, 'store']);         // Create
    Route::put('/todos/{id}', [WebTestController::class, 'update']);     // Update (Toggle Complete)
    Route::delete('/todos/{id}', [WebTestController::class, 'destroy']); // Delete
});
