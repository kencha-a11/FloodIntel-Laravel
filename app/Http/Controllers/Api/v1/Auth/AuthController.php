<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\DB;
use Illuminate\Auth\Events\Registered;
use Carbon\Carbon;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $throttleKey = 'registration:' . $request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 3)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            Log::warning("API Registration throttled for IP: {$request->ip()}. Available in {$seconds}s.");

            return response()->json([
                'message' => "Too many registration attempts. Please try again in {$seconds} seconds."
            ], 429);
        }

        Log::info("API: Starting registration process for a new user from IP: {$request->ip()}");

        try {
            RateLimiter::hit($throttleKey, 60);

            $fields = $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|string|email:dns,filter|unique:users,email',
                'password' => 'required|string|min:8|confirmed',
                'terms' => 'required|accepted',
            ]);

            $user = DB::transaction(function () use ($fields) {
                Log::info("API: Creating user record for: {$fields['email']}");

                $user = User::create([
                    'name' => $fields['name'],
                    'email' => $fields['email'],
                    'password' => Hash::make($fields['password']),
                    'provider_name' => 'email',
                    'provider_id' => null,
                    'terms_accepted_at' => now(),
                ]);

                Log::info("API: Triggering verification event for User ID: {$user->id}");
                event(new Registered($user));

                return $user;
            });

            RateLimiter::clear($throttleKey);

            // Create token for new user (default expiration - session only)
            $token = $user->createToken('auth_token', ['*'], Carbon::now()->addHours(8))->plainTextToken;
            Log::info("API: User ID {$user->id} registered successfully. Token issued (expires in 8 hours).");

            return response()->json([
                'message' => 'Account created! Please check your email to verify.',
                'user' => $user,
                'token' => $token,
                'is_verified' => false,
                'remember' => false
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning("API: Registration validation failed for IP: {$request->ip()}");
            return response()->json([
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            Log::error("API Critical Error during registration: " . $e->getMessage());
            return response()->json([
                'message' => 'Registration failed. An internal error occurred.'
            ], 500);
        }
    }

    public function login(Request $request)
    {
        $throttleKey = 'login:' . strtolower($request->email) . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            Log::warning("API Login throttled for IP: {$request->ip()}. Available in {$seconds}s.");

            return response()->json([
                'message' => "Too many attempts. Please try again in {$seconds} seconds."
            ], 429);
        }

        Log::info("API: Starting login attempt for: {$request->email}");

        try {
            $fields = $request->validate([
                'email' => 'required|email',
                'password' => 'required|string',
                'remember' => 'sometimes|boolean'
            ]);

            $user = User::where('email', $fields['email'])->first();

            if (!$user || !Hash::check($fields['password'], $user->password)) {
                RateLimiter::hit($throttleKey, 60);
                Log::warning("API: Invalid credentials provided for: {$fields['email']}");

                return response()->json([
                    'message' => 'Invalid credentials.'
                ], 401);
            }

            RateLimiter::clear($throttleKey);

            // Check if remember me is checked
            $remember = $request->input('remember', false);

            // First, revoke all existing tokens (optional - for single device login)
            // Uncomment the line below if you want only one active session per user
            // $user->tokens()->delete();

            // Set token expiration based on remember me
            if ($remember) {
                // Create token that expires in 30 days for "Remember Me"
                $expiration = Carbon::now()->addDays(30);
                $token = $user->createToken('auth_token', ['*'], $expiration)->plainTextToken;
                Log::info("API: Remember me ENABLED for user {$user->id}. Token expires in 30 days.");
            } else {
                // Create token that expires in 8 hours for session only
                $expiration = Carbon::now()->addHours(8);
                $token = $user->createToken('auth_token', ['*'], $expiration)->plainTextToken;
                Log::info("API: Remember me DISABLED for user {$user->id}. Token expires in 8 hours.");
            }

            // Prepare response based on email verification status
            if (!$user->hasVerifiedEmail()) {
                Log::info("API: Unverified user {$user->id} logged in.");

                return response()->json([
                    'message' => 'Login successful but email is not verified.',
                    'token' => $token,
                    'user' => $user,
                    'is_verified' => false,
                    'remember' => $remember
                ], 200);
            }

            Log::info("API: Login successful for user ID: {$user->id}. Token issued.");

            return response()->json([
                'message' => 'Login successful',
                'token' => $token,
                'user' => $user,
                'is_verified' => true,
                'remember' => $remember
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning("API: Login validation failed for: {$request->email}");
            return response()->json([
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            Log::error("API Critical Error during login for {$request->email}: " . $e->getMessage());
            return response()->json([
                'message' => 'An internal error occurred. Please try again.'
            ], 500);
        }
    }

    public function logout(Request $request)
    {
        $userId = $request->user()->id;
        Log::info("API: Logout process initiated for User ID: {$userId}");

        try {
            // Revoke the current access token only
            $request->user()->currentAccessToken()->delete();

            Log::info("API: Token successfully revoked for User ID: {$userId}. Logout complete.");

            return response()->json([
                'message' => 'Logged out successfully from the current device.'
            ], 200);

        } catch (\Exception $e) {
            Log::error("API Critical Error during logout for User ID {$userId}: " . $e->getMessage());

            return response()->json([
                'message' => 'An error occurred during logout. Please try again.'
            ], 500);
        }
    }
}
