<?php

use App\Http\Controllers\DiagnosticsController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
|--------------------------------------------------------------------------
# Public diagnostics (no login required)
|--------------------------------------------------------------------------
|
| These routes are intentionally NOT protected by Sanctum or session auth.
| Access is gated solely by a secret query key (`?key=...`) that must match
| the DIAGNOSTICS_KEY environment variable. This lets an operator inspect
| server logs and trigger a test 500 without needing to authenticate first.
|
| Never commit DIAGNOSTICS_KEY to the repository. Set it in Render.
|
*/

Route::middleware([])->get('/diagnostics/logs', [DiagnosticsController::class, 'logs'])->name('diagnostics.logs');

Route::middleware([])->get('/diagnostics/test-500', [DiagnosticsController::class, 'test500'])->name('diagnostics.test-500');

// TEMPORARY: no-key log view — remove after debugging
Route::middleware([])->get('/diagnostics/logs-nokey', [DiagnosticsController::class, 'logsNoKey'])->name('diagnostics.logs-nokey');

