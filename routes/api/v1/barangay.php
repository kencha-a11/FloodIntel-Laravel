<?php

use App\Http\Controllers\Api\v1\BarangayController;
use Illuminate\Support\Facades\Route;

Route::get('/map/barangays', [BarangayController::class, 'getBarangayMap']);
Route::get('/map/flood-simulation', [BarangayController::class, 'getFloodSimulation']);
Route::get('/map/balucuc-data', [BarangayController::class, 'getBalucucData']);
