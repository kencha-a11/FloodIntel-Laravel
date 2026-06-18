<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

class PasswordResetController extends Controller
{
    public function sendResetLink(Request $request)
    {
        try {
            $request->validate(['email' => 'required|email|max:255']); // ✅ Dagdag ng max:255 para sa DB safety
        } catch (ValidationException $e) {
            Log::warning("API: Password reset validation failed for incoming payload.");
            return response()->json(['errors' => $e->errors()], 422);
        }

        $email = strtolower(trim($request->email));
        $clientIp = $request->ip() ?? 'global_fallback';
        $throttleKey = 'password-reset:' . $email . '|' . $clientIp;

        try {
            if (RateLimiter::tooManyAttempts($throttleKey, 3)) {
                $seconds = RateLimiter::availableIn($throttleKey);
                Log::warning("API Password Reset throttled for email: {$email} from IP: {$clientIp}. Try again in {$seconds}s.");
                return response()->json([
                    'message' => "Too many requests. Please wait {$seconds} seconds."
                ], 429);
            }

            RateLimiter::hit($throttleKey, 900);

            $status = Password::sendResetLink($request->only('email'));

            // ✅ Maganda: Palaging generic ang response para iwas user enumeration
            $message = 'If an account exists for this email, you will receive a password reset link shortly.';

            if ($status === Password::RESET_LINK_SENT) {
                Log::info("API: Password reset link sent successfully for: {$email}");
                RateLimiter::clear($throttleKey);
            } else {
                Log::warning("API: Password reset broker failed for {$email}. Status: " . __($status));
            }

            return response()->json(['message' => $message], 200);

        } catch (Throwable $e) {
            Log::critical("API Critical Error during password reset for {$email}: " . $e->getMessage(), [
                'exception' => $e
            ]);
            return response()->json([
                'message' => 'An internal error occurred while processing your request.'
            ], 500);
        }
    }

    public function redirectToResetForm($token, Request $request)
    {
        // Initialize ang base URL sa simula para sa DRY design
        $baseUrl = rtrim(config('app.frontend_url', 'http://localhost:5173'), '/');

        try {
            // I-normalize muna ang email bago i-validate
            $email = trim($request->query('email'));

            // I-validate kung may email AT kung tama ang format nito
            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                Log::warning("API: Password reset redirect blocked. Missing or invalid email format in query.");
                return redirect()->away($baseUrl . '/reset-password?error=invalid_email');
            }

            // I-normalize ang email sa lowercase para sa consistency
            $email = strtolower($email);

            // Privacy-Conscious Logging (Shortened token + obfuscated email)
            Log::info("API: Password reset redirect initiated for token: " . substr($token, 0, 8) . "...");
            Log::info("API: Redirecting user to frontend password reset form for email: " . $this->obfuscateEmail($email));

            // Build the frontend URL securely
            $targetUrl = $baseUrl . '/reset-password?' . http_build_query([
                'token' => $token,
                'email' => $email
            ]);

            return redirect()->away($targetUrl);

        } catch (Throwable $e) {
            Log::critical("API Critical Error during password reset redirect: " . $e->getMessage(), [
                'exception' => $e
            ]);

            // Ligtas na gagamitin ang $baseUrl na idineklara sa itaas
            return redirect()->away($baseUrl . '/reset-password?error=redirect_failed');
        }
    }

    public function resetPassword(Request $request)
    {
        try {
            // 1. Strict Form Input Validation
            $request->validate([
                'token' => 'required|string',
                'email' => 'required|email',
                'password' => 'required|string|min:8|confirmed',
            ]);

            // 2. FIXED: Pagsamahin ang Email at IP para sa throttling target defense
            $email = strtolower(trim($request->email));
            $clientIp = $request->ip() ?? 'global_fallback';
            $throttleKey = 'password-update:' . $email . '|' . $clientIp;

            // 3. Check rate limit
            if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
                $seconds = RateLimiter::availableIn($throttleKey);
                Log::warning("API Password Update throttled for email: {$email} from IP: {$clientIp}. Available in {$seconds}s.");
                return response()->json([
                    'message' => "Too many password reset attempts for this account. Please try again in {$seconds} seconds."
                ], 429);
            }

            // 4. Hit the limiter
            RateLimiter::hit($throttleKey, 900); // 15 minutes window

            Log::info("API: Password reset attempt received for email: {$email}");

            // 5. Process password reset via Laravel Broker
            $status = Password::reset(
                $request->only('email', 'password', 'password_confirmation', 'token'),
                function ($user, $password) {
                    // Update password securely
                    $user->forceFill([
                        'password' => Hash::make($password)
                    ])->save();

                    Log::info("API: Password successfully updated in database for User ID: {$user->id}");

                    // 🌟 SECURITY FEATURE: Revoke all old tokens
                    if (method_exists($user, 'tokens')) {
                        $user->tokens()->delete();
                        Log::info("API: All active sessions revoked for User ID: {$user->id} due to password reset.");
                    }
                }
            );

            // 6. Responses
            if ($status === Password::PASSWORD_RESET) {
                Log::info("API: Password reset successful for: {$email}");
                RateLimiter::clear($throttleKey);
                return response()->json([
                    'message' => __($status)
                ], 200);
            }

            // FIXED: Ginawang 400 Bad Request dahil ito ay token/broker issue, hindi form validation failure
            Log::warning("API: Password reset broker rejected for: {$email}. Status: " . __($status));
            return response()->json([
                'error' => __($status)
            ], 400);

        } catch (ValidationException $e) {
            Log::warning("API: Password reset form validation failed for incoming payload.");
            return response()->json([
                'errors' => $e->errors()
            ], 422);

        } catch (Throwable $e) {
            Log::critical("API Critical Error during password reset processing: " . $e->getMessage(), [
                'exception' => $e
            ]);
            return response()->json([
                'message' => 'An internal error occurred while resetting your password.'
            ], 500);
        }
    }

    private function obfuscateEmail($email): string
    {
        if (empty($email) || !str_contains($email, '@')) {
            return 'invalid';
        }

        $parts = explode("@", $email);
        $name = $parts[0];
        $domain = $parts[1];

        // Kung ang username ay 2 characters or less, huwag nang obfuscate
        if (strlen($name) <= 2) {
            return $name . '@' . $domain;
        }

        $obfuscatedName = substr($name, 0, 1) . '***' . substr($name, -1);
        return $obfuscatedName . '@' . $domain;
    }
}
