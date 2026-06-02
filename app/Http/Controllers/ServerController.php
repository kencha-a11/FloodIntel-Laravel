<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
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
        // Step 1: Request received
        Log::info("Step 1: Server Login View Requested.");

        // Step 2: Check existing session
        if (auth()->check()) {
            Log::info("Step 2: User already logged in (ID: " . auth()->id() . "). Redirecting to Dashboard.");
            return redirect()->route('server.dashboard');
        }

        // Step 3: Render login view
        Log::info("Step 3: Rendering login view for Guest user.");
        return view('server.login');
    }

    public function showDashboard()
    {
        // Step 1: Log the request
        Log::info("Step 1: Server Dashboard View Requested by User ID: " . auth()->id());

        try {
            // Step 2: Dashboard constraints check
            // Note: Middleware 'auth', 'verified', 'terms' already validated the user before reaching here.
            Log::info("Step 2: Dashboard constraints met (Auth, Verified, T&C). Attempting to render view.");

            return view('server.dashboard');

        } catch (\Exception $e) {
            // Step 3: Handle rendering errors
            Log::error("Step 3: Critical error rendering dashboard for User ID " . auth()->id() . ": " . $e->getMessage());

            return redirect()->route('server.login')->with('error', 'Unable to load dashboard. Please try again.');
        }
    }

    public function register(Request $request)
    {
        $throttleKey = 'registration:' . $request->ip();

        // Step 1: Rate Limiting Check
        if (RateLimiter::tooManyAttempts($throttleKey, 3)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            Log::warning("Step 1: Registration throttled for IP: " . $request->ip() . ". Try again in {$seconds}s.");

            return redirect()->back()
                ->with('error', 'Too many registration attempts. Please try again in ' . $seconds . ' seconds.')
                ->withInput($request->except('password', 'password_confirmation'));
        }

        Log::info("Step 2: Starting registration process for new user.");

        try {
            RateLimiter::hit($throttleKey, 60);

            // Step 3: Input Validation
            Log::info("Step 3: Validating registration input fields.");
            $fields = $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|string|email:dns,filter|unique:users,email',
                'password' => 'required|string|min:8|confirmed',
                'terms' => 'required|accepted',
            ]);

            // Step 4: Database Transaction
            $user = DB::transaction(function () use ($fields) {
                Log::info("Step 4: Creating user record for: " . $fields['email']);
                $user = User::create([
                    'name' => $fields['name'],
                    'email' => $fields['email'],
                    'password' => Hash::make($fields['password']),
                    'provider_name' => 'email',
                    'provider_id' => null,
                    'terms_accepted_at' => now(),
                ]);

                Log::info("Step 5: Triggering verification event for User ID: " . $user->id);
                event(new Registered($user));

                return $user;
            });

            RateLimiter::clear($throttleKey);

            // Step 6: Security and Authentication
            $request->session()->regenerate();
            auth()->login($user);
            Log::info("Step 6: User ID {$user->id} registered, session regenerated, and logged in.");

            return redirect()->route('server.verification.notice')
                ->with('status', 'Account created! Please check your email to verify.');

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning("Step 3b: Registration validation failed.");
            return redirect()->back()
                ->withErrors($e->errors())
                ->withInput($request->except('password', 'password_confirmation'));

        } catch (\Exception $e) {
            Log::error("Step 4b: Registration Critical Error: " . $e->getMessage());
            return redirect()->back()
                ->with('error', 'Registration failed. Please try again.')
                ->withInput($request->except('password', 'password_confirmation'));
        }
    }

    public function login(Request $request)
    {
        $throttleKey = 'login:' . strtolower($request->email) . '|' . $request->ip();

        // Step 1: Rate Limiting
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            Log::warning("Step 1: Login throttled for IP: {$request->ip()}. Available in {$seconds}s.");
            return back()->with('error', "Too many attempts. Try again in {$seconds} seconds.");
        }

        Log::info("Step 2: Starting login attempt for: {$request->email}");

        try {
            // Step 3: Input Validation
            $fields = $request->validate([
                'email' => 'required|email',
                'password' => 'required|string'
            ]);

            $user = User::where('email', $fields['email'])->first();

            // Step 4: Credential Verification
            if (!$user || !Hash::check($fields['password'], $user->password)) {
                RateLimiter::hit($throttleKey, 60);
                Log::warning("Step 4: Invalid credentials provided for: {$fields['email']}");
                return back()->with('error', 'Invalid credentials.')->withInput($request->except('password'));
            }

            // Step 5: Email Verification Check
            if (!$user->hasVerifiedEmail()) {
                RateLimiter::clear($throttleKey);
                Log::info("Step 5a: Unverified user {$user->id} detected. Redirecting to verification notice.");

                auth()->login($user, $request->boolean('remember'));
                $request->session()->regenerate();

                return redirect()->route('server.verification.notice')
                    ->with('error', 'Please verify your email address before accessing the dashboard.');
            }

            // Step 6: Full Authentication & Session Management
            RateLimiter::clear($throttleKey);
            $request->session()->regenerate();
            auth()->login($user, $request->boolean('remember'));

            Log::info("Step 6: Login successful for user ID: {$user->id}. Redirecting to dashboard.");
            return redirect()->route('server.dashboard')->with('status', 'Logged in successfully!');

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning("Step 3b: Login validation failed for: {$request->email}");
            return back()->withErrors($e->errors())->withInput();

        } catch (\Exception $e) {
            Log::error("Step 7: Login critical error for {$request->email}: " . $e->getMessage());
            return back()->with('error', 'An internal error occurred. Please try again.');
        }
    }

    public function handleGoogleCallback()
    {
        // ========================================================================
        // STEP 1: INITIALIZE GOOGLE CALLBACK PROCESS
        // ========================================================================
        Log::info("======================================================================");
        Log::info("GOOGLE CALLBACK: Step 1 - Initializing Google Callback Process");
        Log::info("======================================================================");
        Log::info("Timestamp: " . now()->toDateTimeString());
        Log::info("Request URL: " . request()->fullUrl());
        Log::info("Request Method: " . request()->method());
        Log::info("IP Address: " . request()->ip());
        Log::info("User Agent: " . request()->userAgent());

        // Log all request parameters (except sensitive data)
        Log::info("Request Parameters:", request()->except('code'));

        // Check if there's an error from Google
        if (request()->has('error')) {
            Log::error("GOOGLE CALLBACK: Google returned an error: " . request()->get('error'));
            Log::error("GOOGLE CALLBACK: Error description: " . request()->get('error_description', 'No description'));
            return redirect()->route('server.login')->with('error', 'Google authentication failed: ' . request()->get('error'));
        }

        // Check if code is present
        if (!request()->has('code')) {
            Log::error("GOOGLE CALLBACK: No authorization code received from Google");
            return redirect()->route('server.login')->with('error', 'No authorization code received from Google.');
        }

        Log::info("GOOGLE CALLBACK: Authorization code received successfully");

        try {
            // ========================================================================
            // STEP 2: FETCH USER DATA FROM GOOGLE
            // ========================================================================
            Log::info("======================================================================");
            Log::info("GOOGLE CALLBACK: Step 2 - Fetching user data from Google API");
            Log::info("======================================================================");
            Log::info("Attempting to fetch user data using Socialite (stateless mode)...");

            $startTime = microtime(true);
            $socialUser = Socialite::driver('google')->stateless()->user();
            $endTime = microtime(true);
            $executionTime = round(($endTime - $startTime) * 1000, 2);

            Log::info("GOOGLE CALLBACK: User data fetched successfully in {$executionTime}ms");

            // Extract user data
            $email = $socialUser->getEmail();
            $name = $socialUser->getName();
            $googleId = $socialUser->getId();
            $avatar = $socialUser->getAvatar();

            Log::info("GOOGLE CALLBACK: User Data Extracted:");
            Log::info("  - Email: {$email}");
            Log::info("  - Name: {$name}");
            Log::info("  - Google ID: {$googleId}");
            Log::info("  - Avatar: " . ($avatar ? substr($avatar, 0, 50) . "..." : "Not provided"));
            Log::info("  - Raw User Data: ", $socialUser->getRaw());

            // Validate email
            if (empty($email)) {
                Log::error("GOOGLE CALLBACK: No email address provided by Google account");
                return redirect()->route('server.login')->with('error', 'Your Google account does not have an email address. Please use a different account.');
            }

            // ========================================================================
            // STEP 3: CHECK IF USER EXISTS IN DATABASE
            // ========================================================================
            Log::info("======================================================================");
            Log::info("GOOGLE CALLBACK: Step 3 - Checking user existence in database");
            Log::info("======================================================================");
            Log::info("Searching for user with email: {$email}");

            $user = User::where('email', $email)->first();

            if ($user) {
                Log::info("GOOGLE CALLBACK: User FOUND in database");
                Log::info("  - User ID: {$user->id}");
                Log::info("  - Current provider_name: " . ($user->provider_name ?? 'null'));
                Log::info("  - Current provider_id: " . ($user->provider_id ?? 'null'));
                Log::info("  - Current email_verified_at: " . ($user->email_verified_at ?? 'null'));
                Log::info("  - Current terms_accepted_at: " . ($user->terms_accepted_at ?? 'null'));
            } else {
                Log::info("GOOGLE CALLBACK: User NOT FOUND in database - Will create new account");
            }

            // ========================================================================
            // STEP 4: ACCOUNT LINKING / CREATION
            // ========================================================================
            Log::info("======================================================================");
            Log::info("GOOGLE CALLBACK: Step 4 - Account linking/creation");
            Log::info("======================================================================");

            if ($user) {
                // EXISTING USER: Update provider information
                Log::info("GOOGLE CALLBACK: Updating existing user with Google provider info");
                Log::info("  - Updating provider_name: google");
                Log::info("  - Updating provider_id: {$googleId}");
                Log::info("  - Setting email_verified_at to now()");

                $updateData = [
                    'provider_name' => 'google',
                    'provider_id' => $googleId,
                    'email_verified_at' => now(),
                ];

                Log::info("GOOGLE CALLBACK: Update data: ", $updateData);

                $user->update($updateData);

                Log::info("GOOGLE CALLBACK: User updated successfully");
                Log::info("  - Updated provider_name: {$user->provider_name}");
                Log::info("  - Updated provider_id: {$user->provider_id}");
                Log::info("  - Updated email_verified_at: {$user->email_verified_at}");

            } else {
                // NEW USER: Create account
                Log::info("GOOGLE CALLBACK: Creating new user account");

                $randomPassword = Str::random(32);
                Log::info("  - Generated random password (length: " . strlen($randomPassword) . ")");

                $createData = [
                    'email' => $email,
                    'name' => $name,
                    'provider_name' => 'google',
                    'provider_id' => $googleId,
                    'password' => Hash::make($randomPassword),
                    'email_verified_at' => now(),
                    'terms_accepted_at' => null,
                ];

                Log::info("GOOGLE CALLBACK: Create data: ", [
                    'email' => $createData['email'],
                    'name' => $createData['name'],
                    'provider_name' => $createData['provider_name'],
                    'provider_id' => $createData['provider_id'],
                    'email_verified_at' => $createData['email_verified_at']->toDateTimeString(),
                    'terms_accepted_at' => $createData['terms_accepted_at'],
                ]);

                $user = User::create($createData);

                Log::info("GOOGLE CALLBACK: New user created successfully");
                Log::info("  - New User ID: {$user->id}");
                Log::info("  - Created at: {$user->created_at}");
            }

            // ========================================================================
            // STEP 5: VERIFY USER DATA AFTER SAVE
            // ========================================================================
            Log::info("======================================================================");
            Log::info("GOOGLE CALLBACK: Step 5 - Verifying saved user data");
            Log::info("======================================================================");

            // Refresh user data from database
            $user->refresh();

            Log::info("GOOGLE CALLBACK: User data after save:");
            Log::info("  - ID: {$user->id}");
            Log::info("  - Name: {$user->name}");
            Log::info("  - Email: {$user->email}");
            Log::info("  - Provider Name: " . ($user->provider_name ?? 'null'));
            Log::info("  - Provider ID: " . ($user->provider_id ?? 'null'));
            Log::info("  - Email Verified At: " . ($user->email_verified_at ?? 'null'));
            Log::info("  - Terms Accepted At: " . ($user->terms_accepted_at ?? 'null'));
            Log::info("  - Created At: {$user->created_at}");
            Log::info("  - Updated At: {$user->updated_at}");

            // ========================================================================
            // STEP 6: LOGIN USER
            // ========================================================================
            Log::info("======================================================================");
            Log::info("GOOGLE CALLBACK: Step 6 - Logging in user");
            Log::info("======================================================================");

            Log::info("Calling auth()->login() for User ID: {$user->id}");
            auth()->login($user, true); // true = remember me
            Log::info("auth()->login() completed successfully");

            // Refresh session
            Log::info("Regenerating session...");
            session()->regenerate();
            Log::info("Session regenerated. New Session ID: " . session()->getId());

            // Get fresh user data after login
            $loggedInUser = auth()->user();
            Log::info("User after login:");
            Log::info("  - Auth check: " . (auth()->check() ? "true" : "false"));
            Log::info("  - User ID from auth: " . ($loggedInUser ? $loggedInUser->id : 'null'));
            Log::info("  - User email from auth: " . ($loggedInUser ? $loggedInUser->email : 'null'));

            // ========================================================================
            // STEP 7: TERMS & CONDITIONS CHECK
            // ========================================================================
            Log::info("======================================================================");
            Log::info("GOOGLE CALLBACK: Step 7 - Checking Terms & Conditions status");
            Log::info("======================================================================");

            // Refresh user data one more time to ensure we have latest terms_accepted_at
            $user->refresh();
            Log::info("Terms accepted status: " . ($user->terms_accepted_at ? "ACCEPTED at {$user->terms_accepted_at}" : "NOT ACCEPTED"));

            if (is_null($user->terms_accepted_at)) {
                Log::info("GOOGLE CALLBACK: User has NOT accepted Terms & Conditions");
                Log::info("  - Redirecting to Terms page (route: server.terms.show)");
                Log::info("  - User will need to accept terms before accessing dashboard");
                Log::info("======================================================================");
                Log::info("GOOGLE CALLBACK: Redirecting to Terms Page");
                Log::info("======================================================================");
                return redirect()->route('server.terms.show');
            }

            Log::info("GOOGLE CALLBACK: User has ALREADY accepted Terms & Conditions");
            Log::info("  - Terms accepted at: {$user->terms_accepted_at}");

            // ========================================================================
            // STEP 8: SUCCESS - REDIRECT TO DASHBOARD
            // ========================================================================
            Log::info("======================================================================");
            Log::info("GOOGLE CALLBACK: Step 8 - Redirecting to Dashboard");
            Log::info("======================================================================");
            Log::info("  - Redirect route: server.dashboard");
            Log::info("  - Status message: Logged in successfully!");
            Log::info("  - User ID: {$user->id}");
            Log::info("  - User Email: {$user->email}");
            Log::info("======================================================================");
            Log::info("GOOGLE CALLBACK: PROCESS COMPLETED SUCCESSFULLY");
            Log::info("======================================================================");

            return redirect()->route('server.dashboard')->with('status', 'Logged in successfully!');

        } catch (\Laravel\Socialite\Two\InvalidStateException $e) {
            // ========================================================================
            // ERROR HANDLING: Invalid State Exception
            // ========================================================================
            Log::error("======================================================================");
            Log::error("GOOGLE CALLBACK: INVALID STATE EXCEPTION");
            Log::error("======================================================================");
            Log::error("Error Message: " . $e->getMessage());
            Log::error("Error Code: " . $e->getCode());
            Log::error("Error File: " . $e->getFile());
            Log::error("Error Line: " . $e->getLine());
            Log::error("Stack Trace: " . $e->getTraceAsString());
            Log::error("======================================================================");
            Log::error("This error occurs when the state parameter doesn't match");
            Log::error("Possible causes:");
            Log::error("  - Session expired during OAuth flow");
            Log::error("  - Multiple requests with same state parameter");
            Log::error("  - Browser cookies were cleared during the process");
            Log::error("======================================================================");

            return redirect()->route('server.login')->with('error', 'Authentication expired. Please try again.');

        } catch (\Laravel\Socialite\Two\ProviderException $e) {
            // ========================================================================
            // ERROR HANDLING: Provider Exception
            // ========================================================================
            Log::error("======================================================================");
            Log::error("GOOGLE CALLBACK: PROVIDER EXCEPTION");
            Log::error("======================================================================");
            Log::error("Error Message: " . $e->getMessage());
            Log::error("Error Code: " . $e->getCode());
            Log::error("Error File: " . $e->getFile());
            Log::error("Error Line: " . $e->getLine());
            Log::error("Stack Trace: " . $e->getTraceAsString());
            Log::error("======================================================================");
            Log::error("This error occurs when Google returns an error response");
            Log::error("Possible causes:");
            Log::error("  - Invalid client ID or secret");
            Log::error("  - Redirect URI mismatch");
            Log::error("  - User denied access");
            Log::error("  - Invalid or expired authorization code");
            Log::error("======================================================================");

            return redirect()->route('server.login')->with('error', 'Google authentication failed. Please try again.');

        } catch (\Throwable $e) {
            // ========================================================================
            // ERROR HANDLING: Generic Exception
            // ========================================================================
            Log::error("======================================================================");
            Log::error("GOOGLE CALLBACK: CRITICAL ERROR");
            Log::error("======================================================================");
            Log::error("Error Type: " . get_class($e));
            Log::error("Error Message: " . $e->getMessage());
            Log::error("Error Code: " . $e->getCode());
            Log::error("Error File: " . $e->getFile());
            Log::error("Error Line: " . $e->getLine());
            Log::error("======================================================================");
            Log::error("Stack Trace:");
            Log::error($e->getTraceAsString());
            Log::error("======================================================================");

            // Log additional debug info
            Log::error("Additional Debug Info:");
            Log::error("  - Session ID: " . (session()->getId() ?? 'No session'));
            Log::error("  - Request has code: " . (request()->has('code') ? 'Yes' : 'No'));
            Log::error("  - Request has state: " . (request()->has('state') ? 'Yes' : 'No'));
            Log::error("  - All request params: ", request()->all());
            Log::error("======================================================================");

            return redirect()->route('server.login')->with('error', 'Authentication failed. Please try again.');
        }
    }

    public function redirectToGoogle()
    {
        // Step 1: Log the initiation of the request
        Log::info("Step 1: Redirecting user to Google Authentication service.");

        try {
            // Step 2: Initiate the Socialite redirect
            // Ang redirect() method ay awtomatikong nagse-set ng state para sa security
            Log::info("Step 2: Preparing Socialite redirect to Google.");

            return Socialite::driver('google')->redirect();

        } catch (\Exception $e) {
            // Step 3: Log failure if redirect fails
            Log::error("Step 3: Failed to initiate Google redirect. Error: " . $e->getMessage());

            return redirect()->route('server.login')
                ->with('error', 'Unable to connect to Google. Please try again later.');
        }
    }

    public function logout(Request $request)
    {
        Log::info("Step 1: Logout process initiated for User ID: " . auth()->id());

        // 1. I-clear ang "remember me" cookie
        // Ang 'remember_web_...' ay ang default na pangalan ng cookie sa Laravel
        $cookie = Cookie::forget('remember_web_' . config('session.cookie'));

        // 2. I-invalidate ang session at i-regenerate ang CSRF token
        auth()->guard()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        Log::info("Step 2: Session invalidated and Remember Me cookie cleared.");

        return redirect()->route('server.login')
            ->with('status', 'You have been logged out successfully.')
            ->withCookie($cookie);
    }

    public function sendResetLink(Request $request)
    {
        // Step 1: Input Validation
        Log::info("Step 1: Password reset request initiated for email: {$request->email}");

        $request->validate(['email' => 'required|email']);

        // Step 2: Attempting to send the reset link
        Log::info("Step 2: Processing password reset link dispatch.");

        $status = Password::sendResetLink($request->only('email'));

        // Step 3: Check status and log result
        if ($status === Password::RESET_LINK_SENT) {
            Log::info("Step 3: Reset link sent successfully to: {$request->email}");
            return back()->with('status', __($status));
        }

        Log::warning("Step 3: Password reset failed. Status: " . __($status));
        return back()->withErrors(['email' => __($status)]);
    }

    public function resetPassword(Request $request)
    {
        // Step 1: Input Validation
        Log::info("Step 1: Password reset attempt received for email: {$request->email}");

        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|min:8|confirmed', // Naghahanap ng 'password_confirmation' sa request
        ]);

        // Step 2: Processing Password Reset
        Log::info("Step 2: Executing password reset service for: {$request->email}");

        // Tiyakin natin na malinis at eksakto ang keys na kailangan ng Password Broker ng Laravel
        $credentials = [
            'email' => $request->email,
            'password' => $request->password,
            'password_confirmation' => $request->password_confirmation,
            'token' => $request->token,
        ];

        $status = Password::reset(
            $credentials,
            function ($user, $password) {
                // Gamitin ang standard na event o direct forceFill
                $user->forceFill([
                    'password' => Hash::make($password)
                ])->save();

                // Kung gusto mong i-login ang user pagkatapos mag-reset (Optional para sa Web)
                // event(new \Illuminate\Auth\Events\PasswordReset($user));
    
                Log::info("Step 2a: Password successfully updated in database for User ID: {$user->id}");
            }
        );

        // Step 3: Check status and log result
        if ($status === Password::PASSWORD_RESET) {
            Log::info("Step 3: Password reset successful for: {$request->email}");
            return redirect()->route('server.login')->with('status', __($status));
        }

        Log::warning("Step 3: Password reset failed for: {$request->email}. Status: " . __($status));

        // Inalis ang nested array sa withErrors para sumunod sa default validation messaging structure
        return back()->withErrors(['email' => __($status)]);
    }

    public function acceptTerms(Request $request)
    {
        // Step 1: Log the request
        Log::info("Step 1: Terms acceptance requested by User ID: " . $request->user()->id);

        try {
            // Step 2: Update user record
            $request->user()->update([
                'terms_accepted_at' => now(),
                'terms_version' => '1.0'
            ]);

            Log::info("Step 2: Terms accepted and saved for User ID: " . $request->user()->id);

            // Step 3: Redirect
            return redirect()->route('server.dashboard');

        } catch (\Exception $e) {
            Log::error("Step 2b: Failed to save terms acceptance for User ID: " . $request->user()->id . ". Error: " . $e->getMessage());
            return back()->with('error', 'Something went wrong. Please try again.');
        }
    }
}