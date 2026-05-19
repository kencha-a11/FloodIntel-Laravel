<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class SocialAuthController extends Controller
{
    /**
     * Redirect the user to the Social Provider's authentication page.
     * For stateless API architectures, this returns the target OAuth redirect URL.
     *
     * @param string $provider
     * @return \Illuminate\Http\JsonResponse
     */
    public function redirectToProvider($provider)
    {
        Log::info("--- Social Auth Redirect Started ---");
        Log::info("Provider: {$provider}");

        try {
            // Generate the external authentication target URL statelessly
            $redirectUrl = Socialite::driver($provider)->stateless()->redirect()->getTargetUrl();

            Log::info("Redirect URL successfully generated for {$provider}");

            return response()->json([
                'url' => $redirectUrl
            ], 200);

        } catch (\Exception $e) {
            Log::error("Social Auth Redirect Error [{$provider}]: " . $e->getMessage());
            return response()->json([
                'message' => "Failed to get authorization URL for {$provider}."
            ], 500);
        }
    }

    /**
     * Obtain the user information from the Provider and route to the local blade dashboard.
     *
     * @param string $provider
     * @return \Illuminate\Http\RedirectResponse
     */
    public function handleProviderCallback($provider)
    {
        Log::info("--- Social Auth Callback Handshake Started ---");
        Log::info("Provider: {$provider}");

        try {
            // 1. Fetch user data from OAuth provider statelessly
            Log::info("Step 1: Fetching user details from {$provider} platform...");
            $socialUser = Socialite::driver($provider)->stateless()->user();

            $email = $socialUser->getEmail();
            Log::info("Handshake valid. Found profile identifier: {$email}");

            if (!$email) {
                Log::warning("Social Auth Failed: Account from {$provider} does not provide a public email address.");

                // FIXED: Replaced fallback pointing to your actual login route
                return redirect()->route('login')->with('error', 'An email address is required from your social provider profile.');
            }

            // 2. Lookup or Initialize user inside Supabase DB via Eloquent
            Log::info("Step 2: Syncing profile records in database...");
            $user = User::firstOrCreate(
                ['email' => $email],
                [
                    'name' => $socialUser->getName() ?? $socialUser->getNickname() ?? 'Social User',
                    'provider_name' => $provider,
                    'provider_id' => $socialUser->getId(),
                    // Assign a secure, random fallback password string since they use OAuth
                    'password' => Hash::make(Str::random(32)),
                ]
            );

            // Safety mechanism: If user registered via email first, sync provider context
            if (empty($user->provider_name)) {
                Log::info("Updating existing native email user account with {$provider} provider linkages.");
                $user->update([
                    'provider_name' => $provider,
                    'provider_id' => $socialUser->getId()
                ]);
            }

            Log::info("User profile synced successfully: ID {$user->id}");

            // 3. Match AuthController API signature by generating a Sanctum token
            Log::info("Step 3: Compiling application Sanctum token access rights...");
            $token = $user->createToken('floodintel_token')->plainTextToken;
            Log::info("Generated Token for Social User {$user->email}: {$token}");

            Log::info("--- Social Auth Process Completed Successfully ---");

            // ADD THIS LINE: Log the user into the local web guard for testing blade files
            auth()->login($user);

            // For testing: Redirect straight to your blade view
            return redirect()->route('dashboard')->with([
                'status' => 'Logged in successfully via ' . ucfirst($provider),
                'auth_token' => $token,
            ]);

        } catch (\Laravel\Socialite\Two\InvalidStateException $e) {
            Log::error("Social Auth State Error: Handshake timed out or state token mismatches.");
            return redirect()->route('login')->with('error', 'Authentication expired. Please retry signing in.');

        } catch (\Exception $e) {
            Log::error("Social Auth Callback Error: " . $e->getMessage());
            return redirect()->route('login')->with('error', 'Something went wrong during social authentication.');
        }
    }
}
