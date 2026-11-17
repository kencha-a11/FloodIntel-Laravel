<?php

use App\Http\Controllers\DiagnosticsController;
use Illuminate\Support\Facades\Route;

// Header-only diagnostics must remain usable without database-backed sessions.
Route::withoutMiddleware('web')->group(function () {
    Route::get('/diagnostics/logs', [DiagnosticsController::class, 'logs'])->name('diagnostics.logs');
    Route::post('/diagnostics/logs-clear', [DiagnosticsController::class, 'clearLogs'])->name('diagnostics.logs-clear');
});

Route::get('/', function () {
    return view('welcome');
});
