<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Exceptions\InvalidStateException;
use Laravel\Socialite\Exceptions\ProviderException;

class SocialAuthController extends Controller
{
    public function redirectToProvider($provider)
    {
        Log::info("======================================================================");
        Log::info("API SOCIAL REDIRECT: Step 1 - Initiating auth redirect URL generation for: {$provider}");
        Log::info("======================================================================");

        try {
            Log::info("API SOCIAL REDIRECT: Step 2 - Generating stateless OAuth target URL.");
            $redirectUrl = Socialite::driver($provider)->stateless()->redirect()->getTargetUrl();

            Log::info("API SOCIAL REDIRECT: SUCCESS - Target URL successfully generated.");
            Log::info("======================================================================");

            return response()->json([
                'success' => true,
                'url' => $redirectUrl
            ], 200);

        } catch (\Exception $e) {
            Log::error("API SOCIAL REDIRECT: CRITICAL ERROR - Failed to generate redirect URL for {$provider}. Error: " . $e->getMessage());
            Log::error("======================================================================");

            return response()->json([
                'message' => "Unable to connect to {$provider} authentication service. Please try again later."
            ], 500);
        }
    }

    public function handleProviderCallback($provider)
    {
        Log::info("======================================================================");
        Log::info("API SOCIAL CALLBACK: Step 1 - Handshake initiated for provider: {$provider}");
        Log::info("======================================================================");

        // Check for OAuth provider errors
        if (request()->has('error')) {
            $errorMsg = request()->get('error_description', request()->get('error'));
            Log::error("API SOCIAL CALLBACK: Provider returned an error: " . $errorMsg);
            return redirect()->away(config('app.frontend_url') . '/auth/callback?' . http_build_query([
                'success' => 'false',
                'message' => 'Social authentication failed: ' . $errorMsg
            ]));
        }

        try {
            Log::info("API SOCIAL CALLBACK: Step 2 - Fetching user data via Socialite");
            $socialUser = Socialite::driver($provider)->stateless()->user();

            $email = $socialUser->getEmail();
            $name = $socialUser->getName() ?? 'Social User';
            $socialId = $socialUser->getId();

            if (empty($email)) {
                Log::error("API SOCIAL CALLBACK: No email address provided by {$provider}");
                return redirect()->away(config('app.frontend_url') . '/auth/callback?' . http_build_query([
                    'success' => 'false',
                    'message' => 'Email is required from your social account provider.'
                ]));
            }

            Log::info("API SOCIAL CALLBACK: Step 3 - Syncing user record for: {$email}");
            $user = User::where('email', $email)->first();

            if ($user) {
                Log::info("API SOCIAL CALLBACK: Existing user found (ID: {$user->id}). Linking profile.");
                $user->update([
                    'provider_name' => $provider,
                    'provider_id' => $socialId,
                    'email_verified_at' => $user->email_verified_at ?? now(),
                ]);
            } else {
                Log::info("API SOCIAL CALLBACK: User not found. Registering new social account.");
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

            $user->refresh();

            Log::info("API SOCIAL CALLBACK: Step 4 - Generating Sanctum token.");
            $token = $user->createToken('auth_token')->plainTextToken;

            Log::info("API SOCIAL CALLBACK: SUCCESS - Authentication complete for User ID: {$user->id}");
            Log::info("======================================================================");

            // Prepare user data for frontend (only needed fields)
            $userData = $user->only(['id', 'name', 'email', 'provider_name', 'email_verified_at', 'terms_accepted_at']);

            // Redirect to frontend callback URL with token and user data
            $frontendUrl = config('app.frontend_url', 'http://localhost:5173');
            $redirectParams = [
                'success' => 'true',
                'token' => $token,
                'user' => urlencode(json_encode($userData)),
                'has_accepted_terms' => !is_null($user->terms_accepted_at) ? 'true' : 'false',
                'message' => 'Social login successful.'
            ];
            $redirectUrl = $frontendUrl . '/auth/callback?' . http_build_query($redirectParams);

            return redirect()->away($redirectUrl);

        } catch (InvalidStateException $e) {
            Log::error("API SOCIAL CALLBACK: Invalid State Exception - " . $e->getMessage());
            return redirect()->away(config('app.frontend_url') . '/auth/callback?' . http_build_query([
                'success' => 'false',
                'message' => 'Authentication state expired or invalid. Please try again.'
            ]));

        } catch (\GuzzleHttp\Exception\ClientException $e) {
            Log::error("API SOCIAL CALLBACK: Guzzle Client Exception - " . $e->getMessage());
            return redirect()->away(config('app.frontend_url') . '/auth/callback?' . http_build_query([
                'success' => 'false',
                'message' => 'Failed to retrieve user data from the provider.'
            ]));

        } catch (\Throwable $e) {
            Log::error("API SOCIAL CALLBACK: CRITICAL ERROR - Type: " . get_class($e) . " | Message: " . $e->getMessage());
            return redirect()->away(config('app.frontend_url') . '/auth/callback?' . http_build_query([
                'success' => 'false',
                'message' => 'An internal server error occurred during authentication.'
            ]));
        }
    }
}
