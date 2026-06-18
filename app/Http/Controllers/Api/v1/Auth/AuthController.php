<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Auth;
use Throwable;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        // 1. Fallback string prevents a shared global throttle bucket if proxies aren't trusted yet
        $throttleKey = 'registration:' . ($request->ip() ?? 'global_fallback');

        // Check rate limit threshold before executing heavy application logic
        if (RateLimiter::tooManyAttempts($throttleKey, 3)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            Log::warning("API Registration throttled for IP: {$request->ip()}. Available in {$seconds}s.");

            return response()->json([
                'message' => "Too many registration attempts. Please try again in {$seconds} seconds."
            ], 429);
        }

        try {
            // 2. Validate input fields BEFORE hitting the rate limiter.
            // OPTIMIZATION: Removed 'dns' to prevent slow network lookups from stalling PHP workers.
            $fields = $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|string|email:filter|unique:users,email',
                'password' => 'required|string|min:8|confirmed',
                'terms' => 'required|accepted',
            ]);

            // 3. Increment the throttle counter only after verifying data format is valid
            RateLimiter::hit($throttleKey, 60);

            Log::info("API: Starting registration process for a new user from IP: {$request->ip()}");

            // 4. DB Transaction isolates strictly database-level entries
            $user = DB::transaction(function () use ($fields) {
                Log::info("API: Creating user record for: {$fields['email']}");

                return User::create([
                    'name' => $fields['name'],
                    'email' => $fields['email'],
                    'password' => Hash::make($fields['password']),
                    'provider_name' => 'email',
                    'provider_id' => null,
                    'terms_accepted_at' => now(),
                ]);
            });

            // 5. UX FIX: Dispatch email sending execution AFTER the HTTP response is sent.
            // If email service provider is down, the user still logs in successfully.
            dispatch(function () use ($user) {
                try {
                    Log::info("API [Background]: Triggering verification event for User ID: {$user->id}");
                    event(new Registered($user));
                } catch (Throwable $mailException) {
                    Log::critical("API [Background]: Mail delivery failed for User ID {$user->id}: " . $mailException->getMessage());
                }
            })->afterResponse();

            // 6. Issue the access token (Defaulting to an 8-hour lifecycle)
            $token = $user->createToken('auth_token', ['*'], Carbon::now()->addHours(8))->plainTextToken;

            // 7. SECURITY FIX: Clear rate limiter ONLY when everything is 100% successful
            RateLimiter::clear($throttleKey);

            Log::info("API: User ID {$user->id} registered successfully. Token issued.");

            return response()->json([
                'message' => 'Account created! Please check your email to verify.',
                'user' => $user,
                'token' => $token,
                'is_verified' => false,
                'remember' => false
            ], 201);

        } catch (ValidationException $e) {
            Log::warning("API: Registration validation failed for IP: {$request->ip()}");
            return response()->json([
                'errors' => $e->errors()
            ], 422);

        } catch (QueryException $e) {
            // 8. PORTABILITY FIX: Mitigate Concurrency Race Conditions across multiple DB drivers safely
            if ($e->getCode() === '23000' || str_contains($e->getMessage(), 'Duplicate entry')) {
                Log::warning("API: Database unique constraint hit for email registration attempt.");
                return response()->json([
                    'message' => 'This email is already registered.'
                ], 422);
            }

            Log::error("Database error during registration: " . $e->getMessage());
            return response()->json([
                'message' => 'An internal database error occurred during registration.'
            ], 500);

        } catch (Throwable $e) {
            // 9. Catching 'Throwable' safely intercepts fatal PHP engine errors along with standard Exceptions
            Log::error("API Critical Error during registration: " . $e->getMessage());
            return response()->json([
                'message' => 'Registration failed. An internal error occurred.'
            ], 500);
        }
    }

    public function login(Request $request)
    {
        // 1. Fallback mechanisms handle un-trusted proxy setups or missing inputs gracefully
        $clientIp = $request->ip() ?? 'global_fallback';
        $normalizedEmail = $request->email ? strtolower($request->email) : 'invalid_email';
        $throttleKey = 'login:' . $normalizedEmail . '|' . $clientIp;

        // Check rate limit threshold before executing heavy resource logic
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            Log::warning("API Login throttled for IP: {$clientIp}. Available in {$seconds}s.");

            return response()->json([
                'message' => "Too many attempts. Please try again in {$seconds} seconds."
            ], 429);
        }

        try {
            // 2. Validate input fields FIRST to prevent malicious formats from polluting metrics or storage
            $fields = $request->validate([
                'email' => 'required|email|string',
                'password' => 'required|string',
                'remember' => 'sometimes|boolean'
            ]);

            Log::info("API: Starting login attempt for: {$fields['email']} from IP: {$clientIp}");

            // Find the user record
            $user = User::where('email', $fields['email'])->first();

            // 3. Keep standard fallback failure matching to mitigate timing attack vectors
            if (!$user || !Hash::check($fields['password'], $user->password)) {
                RateLimiter::hit($throttleKey, 60);
                Log::warning("API: Invalid credentials provided for: {$fields['email']} from IP: {$clientIp}");

                return response()->json([
                    'message' => 'Invalid credentials.'
                ], 401);
            }

            // Prepare token configuration values
            $remember = (bool) ($fields['remember'] ?? false);
            $expiration = $remember ? Carbon::now()->addDays(30) : Carbon::now()->addHours(8);
            $logMessageContext = $remember ? "30 days (Remember Me)" : "8 hours";

            // Optional: uncomment below if you strictly allow single device session access control rules
            // $user->tokens()->delete();

            // 4. Issue personal access token safely
            $token = $user->createToken('auth_token', ['*'], $expiration)->plainTextToken;
            Log::info("API: Token generated successfully for User ID {$user->id}. Lifetime: {$logMessageContext}.");

            // 5. SECURITY FIX: Clear rate limiter ONLY when the user record, credentials, and tokens are fully processed
            RateLimiter::clear($throttleKey);

            // 6. Return response safely with account context states
            if (!$user->hasVerifiedEmail()) {
                Log::info("API: Unverified user {$user->id} logged in from IP: {$clientIp}.");

                return response()->json([
                    'message' => 'Login successful but email is not verified.',
                    'token' => $token,
                    'user' => $user,
                    'is_verified' => false,
                    'remember' => $remember
                ], 200);
            }

            Log::info("API: Login successful for user ID: {$user->id}. Response ready.");

            return response()->json([
                'message' => 'Login successful',
                'token' => $token,
                'user' => $user,
                'is_verified' => true,
                'remember' => $remember
            ], 200);

        } catch (ValidationException $e) {
            Log::warning("API: Login validation failed for incoming data on IP: {$clientIp}");
            return response()->json([
                'errors' => $e->errors()
            ], 422);

        } catch (Throwable $e) {
            // Catching 'Throwable' safely intercepts fatal engine execution faults along with runtime exceptions
            Log::error("API Critical Error during login attempt from IP {$clientIp}: " . $e->getMessage());
            return response()->json([
                'message' => 'An internal error occurred. Please try again.'
            ], 500);
        }
    }

    public function logout(Request $request)
    {
        // 1. Use Laravel's built-in Auth::user() to safely retrieve the authenticated user
        $user = Auth::user();

        // 2. Guard against unauthenticated requests (null user)
        if (!$user) {
            Log::warning("API: Logout attempted without a valid session or token.");
            return response()->json([
                'message' => 'No active session found. Already logged out.'
            ], 200);
        }

        $userId = $user->id;
        Log::info("API: Logout process initiated for User ID: {$userId}");

        try {
            // 3. Retrieve the current Sanctum personal access token
            $token = $user->currentAccessToken();

            if ($token) {
                $token->delete();
                Log::info("API: Token successfully revoked for User ID: {$userId}.");
            } else {
                Log::warning("API: No current token found to revoke for User ID: {$userId}.");
            }

            return response()->json([
                'message' => 'Logged out successfully from the current device.'
            ], 200);

        } catch (Throwable $e) {
            // 4. Catch all built-in PHP Errors and Exceptions
            Log::error("API Critical Error during logout for User ID {$userId}: " . $e->getMessage());

            return response()->json([
                'message' => 'An error occurred during logout. Please try again.'
            ], 500);
        }
    }
}
