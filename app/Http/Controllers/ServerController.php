<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class ServerController extends Controller
{
    public function showLogin()
    {
        Log::info("--- Server Login View Requested ---");
        if (auth()->check()) {
            Log::info("User already authenticated, redirecting to dashboard.");
            return redirect()->route('server.dashboard');
        }
        return view('server.login');
    }

    public function showDashboard()
    {
        Log::info("--- Server Dashboard View Requested ---");
        if (!auth()->check()) {
            Log::warning("Unauthorized access attempt to dashboard.");
            return redirect()->route('server.login_view')->with('error', 'Please login first.');
        }
        return view('server.dashboard');
    }

    public function register(Request $request)
    {
        Log::info("--- Server Register Process Started ---");
        Log::info("Request Data: ", $request->except(['password', 'password_confirmation']));

        try {
            Log::info("Step 1: Validating registration input fields...");
            $fields = $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|string|email|unique:users,email',
                'password' => 'required|string|min:8|confirmed'
            ]);
            Log::info("Validation successful for: " . $fields['email']);

            Log::info("Step 2: Creating user record in database...");
            $user = User::create([
                'name' => $fields['name'],
                'email' => $fields['email'],
                'password' => Hash::make($fields['password']),
                'provider_name' => 'email', // Added for consistency
                'provider_id' => null,      // Explicitly null for email registration
            ]);
            Log::info("User created successfully: ID {$user->id}");

            Log::info("Step 3: Creating web session...");
            auth()->login($user);

            Log::info("--- Server Register Process Completed Successfully ---");
            return redirect()->route('server.dashboard')->with('status', 'Account created successfully!');

        } catch (\Exception $e) {
            Log::error("Registration Error: " . $e->getMessage());
            return redirect()->back()->with('error', 'Registration failed.')->withInput();
        }
    }

    public function login(Request $request)
    {
        Log::info("--- Server Login Process Started ---");
        Log::info("Request Data: ", $request->except(['password']));

        try {
            Log::info("Step 1: Validating login credentials...");
            $fields = $request->validate([
                'email' => 'required|string|email',
                'password' => 'required|string'
            ]);
            Log::info("Validation successful for email: " . $fields['email']);

            Log::info("Step 2: Checking user existence in database...");
            $user = User::where('email', $fields['email'])->first();

            Log::info("Step 3: Verifying password hash...");
            if (!$user || !Hash::check($fields['password'], $user->password)) {
                Log::warning("Login failed: Invalid credentials for " . $fields['email']);
                return redirect()->back()->withErrors(['email' => 'Invalid credentials.'])->withInput();
            }

            Log::info("Step 4: Creating web session...");
            auth()->login($user);

            Log::info("--- Server Login Process Completed ---");
            Log::info("User ID: {$user->id} | Provider: " . ($user->provider_name ?? 'email'));

            return redirect()->route('server.dashboard')->with('status', 'Logged in successfully!');

        } catch (\Exception $e) {
            Log::error("Login Error: " . $e->getMessage());
            return redirect()->back()->with('error', 'Login failed.')->withInput();
        }
    }

    public function logout(Request $request)
    {
        Log::info("--- Server Logout Process Started ---");

        Log::info("Step 1: Clearing auth session and invalidating tokens...");
        auth()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        Log::info("--- Server Logout Process Completed Successfully ---");
        return redirect()->route('server.login_view')->with('status', 'Logged out successfully.');
    }

    public function redirectToGoogle()
    {
        Log::info("--- Google OAuth Redirect Started ---");
        return Socialite::driver('google')->redirect();
    }

    public function handleGoogleCallback()
    {
        Log::info("--- Google Callback Process Started ---");

        try {
            Log::info("Step 1: Fetching user details from Google...");
            $socialUser = Socialite::driver('google')->user();

            Log::info("Step 2: Checking user existence in database...");
            $user = User::where('email', $socialUser->getEmail())->first();

            if ($user) {
                Log::info("Step 3: Updating existing user with Google provider info...");
                $user->update([
                    'provider_name' => 'google',
                    'provider_id' => $socialUser->getId(),
                ]);
                Log::info("Updated existing user ID: {$user->id}");
            } else {
                Log::info("Step 3: Creating new user from Google...");
                $user = User::create([
                    'name' => $socialUser->getName(),
                    'email' => $socialUser->getEmail(),
                    'password' => Hash::make(Str::random(32)),
                    'provider_name' => 'google',
                    'provider_id' => $socialUser->getId(),
                ]);
                Log::info("Created new user ID: {$user->id}");
            }

            Log::info("Step 4: Creating web session for social user...");
            auth()->login($user);

            Log::info("--- Google Callback Completed Successfully ---");
            Log::info("Provider ID saved: " . $socialUser->getId());

            return redirect()->route('server.dashboard')->with('status', 'Logged in with Google!');

        } catch (\Exception $e) {
            Log::error("Google Callback Error: " . $e->getMessage());
            return redirect()->route('server.login_view')->with('error', 'Google login failed: ' . $e->getMessage());
        }
    }
}
