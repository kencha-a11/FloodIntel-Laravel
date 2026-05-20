<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class PasswordResetController extends Controller
{
    public function sendResetLink(Request $request)
    {
        // Step 1: Input Validation
        Log::info("Step 1: Password reset request initiated for email: {$request->email}");
        $request->validate(['email' => 'required|email']);

        // Step 2: Attempting to send the reset link
        Log::info("Step 2: Processing password reset link dispatch.");
        $status = Password::sendResetLink($request->only('email'));

        // Step 3: API JSON Response
        if ($status === Password::RESET_LINK_SENT) {
            Log::info("Step 3: Reset link sent successfully to: {$request->email}");
            return response()->json(['message' => __($status)], 200);
        }

        Log::warning("Step 3: Password reset failed. Status: " . __($status));
        return response()->json(['error' => __($status)], 400);
    }

    public function resetPassword(Request $request)
    {
        // Step 1: Input Validation
        Log::info("Step 1: Password reset attempt received for email: {$request->email}");
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|min:8|confirmed',
        ]);

        // Step 2: Processing Password Reset
        Log::info("Step 2: Executing password reset service for: {$request->email}");
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->forceFill([
                    'password' => Hash::make($password)
                ])->save();
                Log::info("Step 2a: Password successfully updated in database for User ID: {$user->id}");
            }
        );

        // Step 3: API JSON Response
        if ($status === Password::PASSWORD_RESET) {
            Log::info("Step 3: Password reset successful for: {$request->email}");
            return response()->json(['message' => __($status)], 200);
        }

        Log::warning("Step 3: Password reset failed for: {$request->email}. Status: " . __($status));
        return response()->json(['error' => __($status)], 422);
    }
}
