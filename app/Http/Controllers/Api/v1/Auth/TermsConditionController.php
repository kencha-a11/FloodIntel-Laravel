<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TermsConditionController extends Controller
{
    public function acceptTerms(Request $request)
    {
        $user = $request->user();

        // Step 1: Log the request
        Log::info("Step 1: Terms acceptance requested by API User ID: " . $user->id);

        try {
            // Step 2: Update user record
            $user->update([
                'terms_accepted_at' => now(),
                'terms_version' => '1.0'
            ]);

            Log::info("Step 2: Terms accepted and saved for API User ID: " . $user->id);

            // Step 3: Return JSON response
            return response()->json([
                'message' => 'Terms and conditions accepted successfully.',
                'terms_accepted_at' => $user->terms_accepted_at
            ], 200);

        } catch (\Exception $e) {
            Log::error("Step 2b: Failed to save terms acceptance for User ID: " . $user->id . ". Error: " . $e->getMessage());

            return response()->json([
                'message' => 'Something went wrong while saving your acceptance.'
            ], 500);
        }
    }
}
