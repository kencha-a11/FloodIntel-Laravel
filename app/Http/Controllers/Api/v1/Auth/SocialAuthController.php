<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use GuzzleHttp\Exception\ClientException;
use Throwable;

class SocialAuthController extends Controller
{
    public function redirectToProvider($provider, Request $request)
    {
        // 1. Validate provider
        $supportedProviders = ['google', 'facebook', 'github', 'twitter'];
        $provider = strtolower(trim($provider));

        if (!in_array($provider, $supportedProviders)) {
            Log::warning("API Social Redirect: Unsupported provider: {$provider} from IP: {$request->ip()}");
            return response()->json(['message' => "Unsupported authentication provider"], 400);
        }

        // 2. Prevent authenticated users from re‑authenticating
        if ($request->user()) {
            Log::warning("API Social Redirect: Authenticated user tried social login. User ID: {$request->user()->id}");
            return response()->json(['message' => 'You are already logged in.'], 400);
        }

        $clientIp = $request->ip() ?? 'global_fallback';
        $throttleKey = 'social-redirect:' . $provider . '|' . $clientIp;

        try {
            // 3. Rate limiting
            if (RateLimiter::tooManyAttempts($throttleKey, 10)) {
                $seconds = RateLimiter::availableIn($throttleKey);
                Log::warning("API Social Redirect: Throttled for provider: {$provider}, IP: {$clientIp}. Available in {$seconds}s.");
                return response()->json([
                    'message' => "Too many attempts. Please try again in {$seconds} seconds."
                ], 429);
            }

            RateLimiter::hit($throttleKey, 60);

            Log::info("API Social Redirect: Generating OAuth URL for provider: {$provider} from IP: {$clientIp}");

            // 4. Get the Socialite driver
            try {
                $driver = Socialite::driver($provider);
            } catch (\InvalidArgumentException $e) {
                Log::error("API Social Redirect: Driver not configured for '{$provider}'. Error: " . $e->getMessage());
                return response()->json(['message' => "Service configuration error. Please contact support."], 500);
            }

            // 5. GENERATE REDIRECT URL – SAFELY HANDLE `stateless()`
            try {
                if (method_exists($driver, 'stateless')) {
                    $redirectUrl = $driver->stateless()->redirect()->getTargetUrl();
                } else {
                    Log::warning("API Social Redirect: Provider '{$provider}' does not support stateless. Falling back to stateful.");
                    $redirectUrl = $driver->redirect()->getTargetUrl();
                }
            } catch (\BadMethodCallException $e) {
                Log::error("API Social Redirect: stateless() not available for '{$provider}'. " . $e->getMessage());
                return response()->json([
                    'message' => "Authentication method not supported by provider. Please try another login option."
                ], 500);
            }

            Log::info("API Social Redirect: Successfully generated redirect URL for provider: {$provider}, IP: {$clientIp}");

            // 6. Clear rate limiter on success
            RateLimiter::clear($throttleKey);

            return response()->json([
                'success' => true,
                'url' => $redirectUrl
            ], 200);

        } catch (Throwable $e) {
            Log::critical("API Social Redirect: Critical error for provider {$provider} from IP {$clientIp}: " . $e->getMessage(), [
                'exception' => $e,
                'provider' => $provider,
                'ip' => $clientIp
            ]);

            return response()->json([
                'message' => "Unable to connect to {$provider} authentication service. Please try again later."
            ], 500);
        }
    }

    public function handleProviderCallback($provider, Request $request)
    {
        // 1. Validate provider
        $supportedProviders = ['google', 'facebook', 'github', 'twitter'];
        $provider = strtolower(trim($provider));

        if (!in_array($provider, $supportedProviders)) {
            Log::warning("API Social Callback: Unsupported provider: {$provider} from IP: {$request->ip()}");
            return $this->redirectWithError('Unsupported authentication provider.');
        }

        $clientIp = $request->ip() ?? 'global_fallback';
        $frontendUrl = rtrim(config('app.frontend_url', 'http://localhost:5173'), '/');

        // 2. Check for OAuth provider errors
        if (request()->has('error')) {
            $errorMsg = $request->input('error_description', $request->input('error'));
            Log::error("API Social Callback: Provider returned an error for {$provider} from IP: {$clientIp}: " . $errorMsg);
            return $this->redirectWithError('Social authentication failed: ' . $errorMsg);
        }

        try {
            Log::info("API Social Callback: Processing callback for provider: {$provider} from IP: {$clientIp}");

            // 3. Fetch user data from Socialite
            $driver = Socialite::driver($provider);
            if (method_exists($driver, 'stateless')) {
                $socialUser = $driver->stateless()->user();
            } else {
                Log::warning("API Social Callback: Provider '{$provider}' does not support stateless. Using stateful.");
                $socialUser = $driver->user();
            }

            $email = $socialUser->getEmail();
            $name = $socialUser->getName() ?? 'Social User';
            $socialId = $socialUser->getId();

            // 4. Validate email
            if (empty($email)) {
                Log::error("API Social Callback: No email provided by {$provider} for IP: {$clientIp}");
                return $this->redirectWithError('Email is required from your social account provider.');
            }

            Log::info("API Social Callback: Processing user record for: {$email}");

            // 5. Find or create user
            $user = User::where('email', $email)->first();

            if ($user) {
                // Update provider details - allow ANY provider
                Log::info("API Social Callback: Existing user found (ID: {$user->id}). Updating provider to: {$provider}");

                $updateData = [
                    'provider_name' => $provider,
                    'provider_id' => $socialId,
                ];

                // Only set email_verified_at if not already set
                if (is_null($user->email_verified_at)) {
                    $updateData['email_verified_at'] = now();
                    Log::info("API Social Callback: Setting email_verified_at for user ID: {$user->id}");
                } else {
                    Log::info("API Social Callback: Email already verified for user ID: {$user->id}. Skipping update.");
                }

                $user->update($updateData);
            } else {
                // Create new user with transaction
                DB::beginTransaction();
                try {
                    $existingUser = User::where('email', $email)->lockForUpdate()->first();

                    if ($existingUser) {
                        $user = $existingUser;
                        $updateData = [
                            'provider_name' => $provider,
                            'provider_id' => $socialId,
                        ];
                        if (is_null($user->email_verified_at)) {
                            $updateData['email_verified_at'] = now();
                        }
                        $user->update($updateData);
                    } else {
                        Log::info("API Social Callback: Creating new user from {$provider} social login");
                        $user = User::create([
                            'name' => $name,
                            'email' => $email,
                            'provider_name' => $provider,
                            'provider_id' => $socialId,
                            'password' => Hash::make(Str::random(32)),
                            'email_verified_at' => now(),
                            'terms_accepted_at' => null,
                        ]);
                    }
                    DB::commit();
                } catch (Throwable $e) {
                    DB::rollBack();
                    throw $e;
                }
            }

            $user->refresh();

            Log::info("API Social Callback: Generating Sanctum token for User ID: {$user->id}");

            // 6. Generate token - NO ENCRYPTION
            $token = $user->createToken('auth_token')->plainTextToken;

            // ✅ SEND PLAIN TOKEN (not encrypted)
            $redirectParams = [
                'success' => 'true',
                'token' => $token,
                'user' => json_encode([  // 👈 ADD THIS - send user data
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'email_verified_at' => $user->email_verified_at,
                ]),
                'has_accepted_terms' => !is_null($user->terms_accepted_at) ? 'true' : 'false',
                'message' => 'Social login successful.'
            ];

            Log::info("API Social Callback: Successfully authenticated User ID: {$user->id}");

            return redirect()->away($frontendUrl . '/auth/callback?' . http_build_query($redirectParams));

        } catch (InvalidStateException $e) {
            Log::error("API Social Callback: Invalid State Exception for {$provider} from IP: {$clientIp}: " . $e->getMessage());
            return $this->redirectWithError('Authentication state expired or invalid. Please try again.');

        } catch (ClientException $e) {
            Log::error("API Social Callback: Guzzle Client Exception for {$provider} from IP: {$clientIp}: " . $e->getMessage());
            return $this->redirectWithError('Failed to retrieve user data from the provider.');

        } catch (Throwable $e) {
            Log::critical("API Social Callback: Critical error for {$provider} from IP: {$clientIp}: " . $e->getMessage(), [
                'exception' => $e,
                'provider' => $provider,
                'ip' => $clientIp
            ]);
            return $this->redirectWithError('An internal server error occurred during authentication.');
        }
    }

    private function redirectWithError(string $message): \Illuminate\Http\RedirectResponse
    {
        $frontendUrl = rtrim(config('app.frontend_url', 'http://localhost:5173'), '/');
        return redirect()->away($frontendUrl . '/auth/callback?' . http_build_query([
            'success' => 'false',
            'message' => $message
        ]));
    }
}