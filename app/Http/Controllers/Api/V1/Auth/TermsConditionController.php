<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

class TermsConditionController extends Controller
{
    public function acceptTerms(Request $request)
    {
        $clientIp = $request->ip() ?? 'global_fallback';
        $userId = $user->id ?? 'unknown'; // <-- Idinagdag para magamit sa logs

        try {
            // 1. Kunin ang user at i-validate ang existence
            $user = $request->user();
            if (!$user) {
                Log::warning("API: Terms acceptance attempted without authenticated user from IP: {$clientIp}");
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }

            // 2. Rate Limiting (User ID-based)
            $throttleKey = 'accept-terms:' . $user->id;

            if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
                $seconds = RateLimiter::availableIn($throttleKey);
                Log::warning("API: Terms acceptance throttled for User ID: {$user->id} from IP: {$clientIp}. Available in {$seconds}s.");
                return response()->json([
                    'message' => "Too many attempts. Please try again in {$seconds} seconds."
                ], 429);
            }

            // 3. Validate input
            $validated = $request->validate([
                'terms_version' => 'required|string|in:1.0,2.0'
            ]);

            RateLimiter::hit($throttleKey, 60);

            Log::info("API: Terms acceptance requested by User ID: {$user->id} for version: {$validated['terms_version']}");

            // 4. Atomic check: Kung same version, wag nang i-hit ang DB
            if ($user->terms_version === $validated['terms_version'] && $user->terms_accepted_at !== null) {
                Log::info("API: User ID {$user->id} already accepted version {$user->terms_version}. Skipping DB update.");
                return response()->json([
                    'success' => true,
                    'message' => 'Terms and conditions already accepted for this version.',
                    'data' => [
                        'terms_version' => $user->terms_version,
                        'terms_accepted_at' => $user->terms_accepted_at
                    ]
                ], 200);
            }

            // 5. I-update ang user record
            $user->update([
                'terms_accepted_at' => now(),
                'terms_version' => $validated['terms_version']
            ]);

            // 6. I-refresh ang user object para makuha ang updated values
            $user->refresh();

            Log::info("API: Terms version {$validated['terms_version']} successfully saved for User ID: {$user->id}");

            // 7. Clear rate limiter on success
            RateLimiter::clear($throttleKey);

            return response()->json([
                'success' => true,
                'message' => 'Terms and conditions accepted successfully.',
                'data' => [
                    'terms_version' => $user->terms_version,
                    'terms_accepted_at' => $user->terms_accepted_at
                ]
            ], 200);

        } catch (ValidationException $e) {
            // FIXED: Gumamit ng $userId variable (na-declare sa itaas)
            Log::warning("API: Terms acceptance validation failed for User ID: {$userId} from IP: {$clientIp}");
            return response()->json(['errors' => $e->errors()], 422);

        } catch (Throwable $e) {
            // FIXED: Gumamit ng $userId variable (na-declare sa itaas)
            Log::critical("API Critical Error during terms acceptance for User ID: {$userId} from IP: {$clientIp}: " . $e->getMessage(), [
                'exception' => $e
            ]);

            return response()->json(['message' => 'An internal error occurred while saving your acceptance.'], 500);
        }
    }
}
