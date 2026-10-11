<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BarangayResource;
use App\Models\Barangay;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BarangayController extends Controller
{
    /**
     * List all barangays.
     */
    public function index(): AnonymousResourceCollection
    {
        return BarangayResource::collection(
            Barangay::query()->orderBy('name')->get(),
        );
    }
}
