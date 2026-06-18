<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ServerController;

// -------------------------------------------------------------
// Core System & API Group
// -------------------------------------------------------------
Route::prefix('server')->group(function () {
    require __DIR__ . '/server.php';
});

// Main landing (API welcome)
Route::get('/', function () {
    return response()->json([
        'message' => 'Welcome to the FloodIntel Server!',
        'documentation' => url('/docs')
    ]);
});

// -------------------------------------------------------------
// Log Viewer (Controller-based for cache safety)
// -------------------------------------------------------------
Route::get('/logs', [ServerController::class, 'showLogs']);
Route::post('/logs/clear', [ServerController::class, 'clearLogs']);

Route::get('/logs/test', function () {
    Log::info('Test log entry at ' . now());
    return response()->json(['message' => 'Test log entry created. ' . now()]);
});
