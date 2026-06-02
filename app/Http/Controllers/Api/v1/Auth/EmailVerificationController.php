<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use App\Models\User;

class EmailVerificationController extends Controller
{
    public function sendVerificationNotification(Request $request)
    {
        $user = $request->user();

        // Rate Limiting Key base sa User ID at IP
        $throttleKey = 'resend-verification:' . $user->id . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 2)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            Log::warning("API: Resend verification throttled for User ID: {$user->id}. Try again in {$seconds}s.");
            return response()->json([
                'message' => "Too many requests. Please wait {$seconds} seconds before requesting another link."
            ], 429);
        }

        RateLimiter::hit($throttleKey, 60);

        Log::info("API: Verification link resend process initiated for User ID: {$user->id}");

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email already verified.'], 400);
        }

        $user->sendEmailVerificationNotification();
        Log::info("API: Verification email successfully resent for User ID: " . $user->id);

        return response()->json(['message' => 'Verification link sent!'], 200);
    }

    public function verifyEmail($id, $hash, Request $request)
    {
        Log::info("API: Processing email verification for user ID: {$id}");

        // Find user by ID
        $user = User::find($id);

        if (!$user) {
            Log::warning("API: User not found for verification: {$id}");
            return redirect()->away(config('app.frontend_url') . '/verified-email?error=invalid_user');
        }

        // Verify the hash
        if (!hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            Log::warning("API: Invalid verification hash for user ID: {$id}");
            return redirect()->away(config('app.frontend_url') . '/verified-email?error=invalid_hash');
        }

        // Check if already verified
        if ($user->hasVerifiedEmail()) {
            Log::info("API: User ID {$id} already verified.");
            return redirect()->away(config('app.frontend_url') . '/verified-email?message=already_verified');
        }

        // Mark email as verified
        if ($user->markEmailAsVerified()) {
            Log::info("API: Email verified successfully for User ID: {$id}");

            // Create a new token for the user para magamit ng frontend
            $token = $user->createToken('auth_token')->plainTextToken;

            // Redirect to frontend with success and token
            return redirect()->away(config('app.frontend_url') . '/verified-email?verified=true&token=' . $token);
        }

        Log::error("API: Failed to verify email for User ID: {$id}");
        return redirect()->away(config('app.frontend_url') . '/verified-email?error=verification_failed');
    }
}
