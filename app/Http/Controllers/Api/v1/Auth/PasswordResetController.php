<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\RateLimiter;

class PasswordResetController extends Controller
{
    public function sendResetLink(Request $request)
    {
        // Gumawa ng natatanging key gamit ang IP at email para sa rate limiting
        $throttleKey = 'password-reset:' . strtolower($request->email) . '|' . $request->ip();

        // Step 1: Rate Limiting Check (Limitahan sa 3 attempts bawat 15 minuto para iwas spam)
        if (RateLimiter::tooManyAttempts($throttleKey, 3)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            Log::warning("API Password Reset throttled for email: {$request->email} from IP: {$request->ip()}. Try again in {$seconds}s.");

            return response()->json([
                'message' => "Too many password reset requests. Please try again in {$seconds} seconds."
            ], 429);
        }

        Log::info("API Step 2: Password reset request initiated for email: {$request->email}");

        try {
            // I-hit ang limiter sa bawat pagsubok
            RateLimiter::hit($throttleKey, 900); // 900 seconds = 15 minutes lock kung lumagpas sa limit

            // Step 3: Input Validation
            $request->validate(['email' => 'required|email']);

            Log::info("API Step 4: Processing password reset link dispatch via Laravel core.");

            // Step 4: Attempting to send the reset link
            // Hahanapin ng Laravel ang named route na `password.reset` na inayos natin sa api.php kanina
            $status = Password::sendResetLink($request->only('email'));

            // Step 5: API JSON Response base sa resulta ng Framework
            if ($status === Password::RESET_LINK_SENT) {
                Log::info("API Step 5: Reset link sent successfully to: {$request->email}");

                // Pag matagumpay, opsyonal na linisin ang limiter (o panatilihin para iwas sunod-sunod na click)
                RateLimiter::clear($throttleKey);

                return response()->json([
                    'message' => __($status) // Magbabalik ng "We have emailed your password reset link."
                ], 200);
            }

            // Kung hindi nagpadala (e.g., "We can't find a user with that email address.")
            Log::warning("API Step 5: Password reset broker failed. Status: " . __($status));
            return response()->json([
                'error' => __($status)
            ], 400);

        } catch (ValidationException $e) {
            Log::warning("API Step 3b: Password reset validation failed for: {$request->email}");
            return response()->json([
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            Log::error("API Critical Error during password reset link generation for {$request->email}: " . $e->getMessage());
            return response()->json([
                'message' => 'An internal error occurred while processing your request.'
            ], 500);
        }
    }
    public function resetPassword(Request $request)
    {
        // Limitahan ang pag-submit ng password reset form base sa kanilang IP address
        $throttleKey = 'password-update:' . $request->ip();

        // Step 1: Rate Limiting Check (Halimbawa: 5 attempts lang kada 15 minuto)
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            Log::warning("API Password Update throttled for IP: {$request->ip()}. Available in {$seconds}s.");

            return response()->json([
                'message' => "Too many attempts. Please try again in {$seconds} seconds."
            ], 429);
        }

        Log::info("API Step 2: Password reset attempt received for email: {$request->email}");

        try {
            // Itala ang bawat attempt ng pag-submit ng form
            RateLimiter::hit($throttleKey, 900); // 15 minutes window

            // Step 3: Input Validation
            $request->validate([
                'token' => 'required',
                'email' => 'required|email',
                'password' => 'required|min:8|confirmed',
            ]);

            Log::info("API Step 4: Executing password reset service for: {$request->email}");

            // Step 4: Processing Password Reset via Laravel Broker
            $status = Password::reset(
                $request->only('email', 'password', 'password_confirmation', 'token'),
                function ($user, $password) {
                    // I-update ang bagong password
                    $user->forceFill([
                        'password' => Hash::make($password)
                    ])->save();

                    Log::info("API Step 4a: Password successfully updated in database for User ID: {$user->id}");

                    // 🌟 SECURITY UPGRADE: Burahin ang LAHAT ng lumang tokens ng user.
                    // Para kung may ibang taong naka-login sa account niya, masisipa sila palabas
                    // at mapipilitang mag-login gamit ang bagong password.
                    $user->tokens()->delete();
                    Log::info("API Step 4b: All active tokens revoked for User ID: {$user->id} due to password reset.");
                }
            );

            // Step 5: API JSON Response
            if ($status === Password::PASSWORD_RESET) {
                Log::info("API Step 5: Password reset successful for: {$request->email}");

                // Burahin na ang rate limit kapag naging matagumpay ang pagpapalit
                RateLimiter::clear($throttleKey);

                return response()->json([
                    'message' => __($status) // Magbabalik ng "Your password has been reset."
                ], 200);
            }

            // Kung hindi tumugma ang token o expired na
            Log::warning("API Step 5: Password reset failed for: {$request->email}. Status: " . __($status));
            return response()->json([
                'error' => __($status)
            ], 422);

        } catch (ValidationException $e) {
            Log::warning("API Step 3b: Password reset form validation failed for: {$request->email}");
            return response()->json([
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            Log::error("API Critical Error during password reset processing for {$request->email}: " . $e->getMessage());
            return response()->json([
                'message' => 'An internal error occurred while resetting your password.'
            ], 500);
        }
    }
}
