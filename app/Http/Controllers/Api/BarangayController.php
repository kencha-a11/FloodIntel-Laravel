<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BarangayResource;
use App\Models\Barangay;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BarangayController extends Controller
{
    /**
     * List the active barangays.
     *
     * Superseded rows stay in the table for their reports but are not offered
     * for selection.
     */
    public function index(): AnonymousResourceCollection
    {
        return BarangayResource::collection(
            Barangay::query()->where('is_active', true)->orderBy('name')->get(),
        );
    }
}
