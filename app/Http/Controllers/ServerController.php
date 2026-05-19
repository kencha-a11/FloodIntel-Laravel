<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Auth\Events\Registered; // Import ito

class ServerController extends Controller
{
    public function showLogin()
    {
        Log::info("--- Server Login View Requested ---");
        if (auth()->check()) {
            return redirect()->route('server.dashboard');
        }
        return view('server.login');
    }

    public function showDashboard()
    {
        Log::info("--- Server Dashboard View Requested ---");
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
            Log::info("Validation successful for email: " . $fields['email']);

            Log::info("Step 2: Creating user record in database...");
            $user = User::create([
                'name' => $fields['name'],
                'email' => $fields['email'],
                'password' => Hash::make($fields['password']),
                'provider_name' => 'email',
                'provider_id' => null,
            ]);
            Log::info("User created successfully: ID {$user->id}");

            Log::info("Step 3: Triggering email verification event...");
            event(new Registered($user));

            Log::info("--- Server Register Process Completed (Verification Sent) ---");

            return redirect()->route('verification.notice')
                ->with('status', 'Account created! Please check your email to verify.');

        } catch (\Illuminate\Validation\ValidationException $e) {
            // Hiwalay na log para sa validation errors
            Log::warning("Registration Validation Failed: ", $e->errors());
            return redirect()->back()->withErrors($e->errors())->withInput();

        } catch (\Exception $e) {
            Log::error("Registration Error: " . $e->getMessage());
            return redirect()->back()->with('error', 'Something went wrong. Please try again.')->withInput();
        }
    }

    public function login(Request $request)
    {
        Log::info("--- Server Login Process Started ---");

        try {
            $fields = $request->validate([
                'email' => 'required|string|email',
                'password' => 'required|string'
            ]);

            $user = User::where('email', $fields['email'])->first();

            if (!$user || !Hash::check($fields['password'], $user->password)) {
                return redirect()->back()->withErrors(['email' => 'Invalid credentials.'])->withInput();
            }

            auth()->login($user);

            Log::info("--- Server Login Process Completed ---");
            return redirect()->route('server.dashboard')->with('status', 'Logged in successfully!');

        } catch (\Exception $e) {
            Log::error("Login Error: " . $e->getMessage());
            return redirect()->back()->with('error', 'Login failed.')->withInput();
        }
    }

    public function handleGoogleCallback()
    {
        Log::info("--- Google Callback Process Started ---");

        try {
            $socialUser = Socialite::driver('google')->user();

            $user = User::updateOrCreate(
                ['email' => $socialUser->getEmail()],
                [
                    'name' => $socialUser->getName(),
                    'provider_name' => 'google',
                    'provider_id' => $socialUser->getId(),
                    'password' => Hash::make(Str::random(32)),
                    'email_verified_at' => now(), // AUTO-VERIFIED
                ]
            );

            auth()->login($user);

            Log::info("--- Google Callback Completed (Auto-Verified) ---");
            return redirect()->route('server.dashboard')->with('status', 'Logged in with Google!');

        } catch (\Exception $e) {
            Log::error("Google Callback Error: " . $e->getMessage());
            return redirect()->route('server.login_view')->with('error', 'Google login failed.');
        }
    }

    public function logout(Request $request)
    {
        auth()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('server.login_view');
    }
}
