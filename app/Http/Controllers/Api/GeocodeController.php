<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Geocode\ReverseGeocodeRequest;
use App\Services\ReverseGeocoder;
use Illuminate\Http\JsonResponse;

class GeocodeController extends Controller
{
    /**
     * Translate coordinates into a human-readable address.
     */
    public function reverse(
        ReverseGeocodeRequest $request,
        ReverseGeocoder $geocoder,
    ): JsonResponse {
        $address = $geocoder->lookup(
            (float) $request->input('latitude'),
            (float) $request->input('longitude'),
        );

        return response()->json([
            'data' => [
                'address' => $address,
            ],
        ]);
    }
}
