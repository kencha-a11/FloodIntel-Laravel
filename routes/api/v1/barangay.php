<?php

use App\Http\Controllers\Api\v1\BarangayController;
use App\Models\Barangay;
use Illuminate\Support\Facades\Route;

Route::get('/barangay', function () {
    return response()->json(['message', 'hello this is barangay']);
});

Route::get('/flood-data', function () {
    // Kunin lahat ng barangay boundaries
    $barangays = Barangay::withGeoJson()->get();

    return response()->json($barangays);
});

Route::get('/map/barangays', [BarangayController::class, 'getBarangayMap']);
Route::get('/map/flood-simulation', [BarangayController::class, 'getFloodSimulation']);
Route::get('/map/balucuc-data', [BarangayController::class, 'getBalucucData']);
