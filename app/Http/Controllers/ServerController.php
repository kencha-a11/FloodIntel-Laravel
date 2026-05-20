<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Auth\Events\Registered; // Import ito
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Password;

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
        // Define unique key base sa IP address
        $throttleKey = 'registration:' . $request->ip();

        // 1. Rate Limiting Check: 3 registration attempts per minute
        if (RateLimiter::tooManyAttempts($throttleKey, 3)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            Log::warning("Registration throttled for IP: " . $request->ip());

            return redirect()->back()
                ->with('error', 'Too many registration attempts. Please try again in ' . $seconds . ' seconds.')
                ->withInput($request->except('password', 'password_confirmation'));
        }

        Log::info("--- Server Register Process Started ---");

        try {
            // Hit the limiter
            RateLimiter::hit($throttleKey, 60);

            Log::info("Step 1: Validating registration input fields...");
            $fields = $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|string|email:dns,filter|unique:users,email',
                'password' => 'required|string|min:8|confirmed'
            ]);

            $user = DB::transaction(function () use ($fields) {
                Log::info("Step 2: Creating user record in database for: " . $fields['email']);
                $user = User::create([
                    'name' => $fields['name'],
                    'email' => $fields['email'],
                    'password' => Hash::make($fields['password']),
                    'provider_name' => 'email',
                    'provider_id' => null,
                ]);

                Log::info("Step 3: Triggering email verification event for User ID: " . $user->id);
                event(new Registered($user));

                return $user;
            });

            // I-clear ang limiter count dahil matagumpay ang registration
            RateLimiter::clear($throttleKey);

            // Security: Regenerate session
            $request->session()->regenerate();

            // Login the user automatically after registration
            auth()->login($user);

            Log::info("--- Server Register Process Completed Successfully ---");
            return redirect()->route('verification.notice')
                ->with('status', 'Account created! Please check your email to verify.');

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning("Registration Validation Failed.");
            return redirect()->back()
                ->withErrors($e->errors())
                ->withInput($request->except('password', 'password_confirmation'));

        } catch (\Exception $e) {
            Log::error("Registration Critical Error: " . $e->getMessage());
            return redirect()->back()
                ->with('error', 'Registration failed. Please try again.')
                ->withInput($request->except('password', 'password_confirmation'));
        }
    }

    public function login(Request $request)
    {
        // Gamitin ang email ng user at IP para sa mas secure na Rate Limiting
        $throttleKey = 'login:' . strtolower($request->email) . '|' . $request->ip();

        // 1. Rate Limiting Check: 5 attempts per minute
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            Log::warning("Login throttled for IP: " . $request->ip() . " Email: " . $request->email);

            return redirect()->back()
                ->with('error', "Too many login attempts. Please try again in {$seconds} seconds.")
                ->withInput($request->except('password'));
        }

        Log::info("--- Server Login Process Started ---");

        try {
            // Validation
            $fields = $request->validate([
                'email' => 'required|email',
                'password' => 'required|string'
            ]);

            $user = User::where('email', $fields['email'])->first();

            // 2. Credential Check
            if (!$user || !Hash::check($fields['password'], $user->password)) {
                // Hit the limiter (60 seconds decay)
                RateLimiter::hit($throttleKey, 60);

                Log::warning("Login failed: Invalid credentials for " . $fields['email']);

                return redirect()->back()
                    ->with('error', 'Invalid credentials.')
                    ->withInput($request->except('password'));
            }

            // 3. Email Verification Check
            if (!$user->hasVerifiedEmail()) {
                Log::warning("Login blocked: Unverified email attempt for: " . $user->email);
                return redirect()->route('verification.notice')
                    ->with('error', 'Please verify your email address before logging in.');
            }

            // 4. Success Actions
            RateLimiter::clear($throttleKey);

            $request->session()->regenerate();
            auth()->login($user, $request->filled('remember'));

            Log::info("--- Login Successful for User ID: {$user->id} ---");
            return redirect()->route('server.dashboard')->with('status', 'Logged in successfully!');

        } catch (\Illuminate\Validation\ValidationException $e) {
            // Handle validation errors (e.g. invalid email format)
            return redirect()->back()
                ->withErrors($e->errors())
                ->withInput($request->except('password'));

        } catch (\Exception $e) {
            Log::error("Login Critical Error: " . $e->getMessage());
            return redirect()->back()
                ->with('error', 'Something went wrong during login. Please try again.')
                ->withInput($request->except('password'));
        }
    }

    public function handleGoogleCallback()
    {
        Log::info("--- Google Callback Process Started ---");

        try {
            Log::info("Step 1: Fetching user data from Google (stateless)...");
            $socialUser = Socialite::driver('google')->stateless()->user();

            $email = $socialUser->getEmail();
            if (!$email) {
                throw new \Exception("Google did not provide an email address.");
            }

            Log::info("Step 2: Checking if user exists in database: " . $email);
            $user = User::where('email', $email)->first();

            // Proteksyon: Huwag payagan ang Google login kung ang user ay email/password base
            if ($user && $user->provider_name === 'email') {
                Log::warning("Step 2.5: Google login blocked. Email exists as local account: " . $email);
                return redirect()->route('login')
                    ->with('error', 'An account with this email already exists. Please log in with your password.');
            }

            Log::info("Step 3: Creating/Updating user record...");
            if ($user) {
                // Kung existing na ang user, i-update lang ang info (HINDI password)
                $user->update([
                    'name' => $socialUser->getName(),
                    'provider_name' => 'google',
                    'provider_id' => $socialUser->getId(),
                    'email_verified_at' => now(),
                ]);
            } else {
                // Kung bagong user, doon lang mag-generate ng random password
                $user = User::create([
                    'email' => $email,
                    'name' => $socialUser->getName(),
                    'provider_name' => 'google',
                    'provider_id' => $socialUser->getId(),
                    'password' => Hash::make(Str::random(32)),
                    'email_verified_at' => now(),
                ]);
            }

            Log::info("Step 4: Logging in user...");

            // Regenerate session para sa security
            request()->session()->regenerate();

            auth()->login($user, true);

            Log::info("--- Google Callback Process Success: User ID {$user->id} ---");
            return redirect()->route('server.dashboard')->with('status', 'Logged in with Google!');

        } catch (\Throwable $e) {
            Log::error("Google Callback Critical Error: " . get_class($e) . " - " . $e->getMessage());
            Log::error("Stack Trace: " . $e->getTraceAsString());

            return redirect()->route('login')
                ->with('error', 'Google authentication failed: ' . $e->getMessage());
        }
    }

    public function redirectToGoogle()
    {
        Log::info("--- Redirecting to Google Auth ---");

        // Gamitin ang standard redirect() para siguradong
        // naise-set ng Socialite ang mga kailangang session states.
        return Socialite::driver('google')->redirect();
    }

    public function logout(Request $request)
    {
        auth()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('login');
    }

    public function sendResetLink(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $status = Password::sendResetLink($request->only('email'));

        return $status === Password::RESET_LINK_SENT
            ? back()->with('status', __($status))
            : back()->withErrors(['email' => __($status)]);
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|min:8|confirmed',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->forceFill(['password' => Hash::make($password)])->save();
            }
        );

        return $status === Password::PASSWORD_RESET
            ? redirect()->route('login')->with('status', __($status))
            : back()->withErrors(['email' => [__($status)]]);
    }
}
