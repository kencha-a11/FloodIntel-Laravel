<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class SocialAuthController extends Controller
{
    public function redirectToProvider($provider)
    {
        Log::info("--- API Social Auth Redirect Started ---");

        try {
            $redirectUrl = Socialite::driver($provider)->stateless()->redirect()->getTargetUrl();
            return response()->json(['url' => $redirectUrl], 200);
        } catch (\Exception $e) {
            Log::error("Social Auth Redirect Error: " . $e->getMessage());
            return response()->json(['message' => "Failed to get auth URL."], 500);
        }
    }

    public function handleProviderCallback($provider)
    {
        Log::info("--- API Social Auth Callback Handshake ---");

        try {
            // 1. Fetch user data
            $socialUser = Socialite::driver($provider)->stateless()->user();
            $email = $socialUser->getEmail();

            if (!$email) {
                return response()->json(['message' => 'Email is required from provider.'], 422);
            }

            // 2. Sync User
            $user = User::firstOrCreate(
                ['email' => $email],
                [
                    'name' => $socialUser->getName() ?? 'Social User',
                    'provider_name' => $provider,
                    'provider_id' => $socialUser->getId(),
                    'password' => Hash::make(Str::random(32)),
                ]
            );

            // 3. Issue Token
            $token = $user->createToken('floodintel_token')->plainTextToken;

            Log::info("--- API Social Auth Success for User ID: {$user->id} ---");

            // IBALIK BILANG JSON (Dito kukuha ng token ang mobile/web app mo)
            return response()->json([
                'success' => true,
                'message' => 'Social login successful.',
                'data' => [
                    'user' => $user,
                    'auth_token' => $token
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error("Social Auth Callback Error: " . $e->getMessage());
            return response()->json(['message' => 'Authentication failed.'], 500);
        }
    }
}
