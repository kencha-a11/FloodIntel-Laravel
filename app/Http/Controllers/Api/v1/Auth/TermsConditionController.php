<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class TermsConditionController extends Controller
{
    public function acceptTerms(Request $request)
    {
        $user = $request->user();
        Log::info("API Step 1: Terms acceptance requested by User ID: " . $user->id);

        try {
            // Step 2: I-validate ang input mula sa Frontend (e.g., kung anong version ang sinasang-ayunan nila)
            $validated = $request->validate([
                'terms_version' => 'required|string' // Inirerekomenda na ipasa ito mula sa frontend app (e.g., '1.0')
            ]);

            // Optimization Check: Kung tinanggap na nila ang bersyong ito dati, huwag nang i-update ang DB
            if ($user->terms_version === $validated['terms_version'] && !is_null($user->terms_accepted_at)) {
                Log::info("API Step 2a: User ID {$user->id} already accepted version {$user->terms_version}. Skipping DB update.");

                return response()->json([
                    'success' => true,
                    'message' => 'Terms and conditions already accepted for this version.',
                    'terms_accepted_at' => $user->terms_accepted_at
                ], 200);
            }

            // Step 3: I-update ang record ng user gamit ang dynamic na bersyon
            $user->update([
                'terms_accepted_at' => now(),
                'terms_version' => $validated['terms_version']
            ]);

            Log::info("API Step 3: Terms version {$validated['terms_version']} successfully saved for User ID: " . $user->id);

            // Step 4: Ibalik ang sariwang data sa JSON response
            return response()->json([
                'success' => true,
                'message' => 'Terms and conditions accepted successfully.',
                'data' => [
                    'terms_version' => $user->terms_version,
                    'terms_accepted_at' => $user->terms_accepted_at
                ]
            ], 200);

        } catch (ValidationException $e) {
            Log::warning("API Step 2b: Validation failed during terms acceptance for User ID: " . $user->id);
            return response()->json([
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            Log::error("API Step 3b: Failed to save terms acceptance for User ID: " . $user->id . ". Error: " . $e->getMessage());

            return response()->json([
                'message' => 'An internal error occurred while saving your acceptance.'
            ], 500);
        }
    }
}