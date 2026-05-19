<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        Log::info("--- Register Process Started ---");
        Log::info("Request Data: ", $request->except(['password', 'password_confirmation']));

        try {
            // 1. Validation
            Log::info("Step 1: Validating input fields...");
            $fields = $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|string|email|unique:users,email',
                'password' => 'required|string|min:8|confirmed'
            ]);
            Log::info("Validation successful for: " . $fields['email']);

            // 2. Create User
            Log::info("Step 2: Creating user in database...");
            $user = User::create([
                'name' => $fields['name'],
                'email' => $fields['email'],
                'password' => Hash::make($fields['password']),
            ]);
            Log::info("User created successfully: ID {$user->id}");

            // 3. Create Token (Sanctum)
            Log::info("Step 3: Generating Sanctum token...");
            $token = $user->createToken('floodintel_token')->plainTextToken;
            Log::info("Generated Token for User {$user->email}: {$token}");

            Log::info("--- Register Process Completed Successfully ---");

            // Log the user into the local web session state for Blade testing
            auth()->login($user);

            return redirect()->route('dashboard')->with([
                'status' => 'Account registered and logged in successfully!',
                'auth_token' => $token
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error("Registration Validation Failed: ", $e->errors());

            // Redirect back to the form with the validation errors and old input
            return redirect()->back()->withErrors($e->errors())->withInput();
        } catch (\Exception $e) {
            Log::error("Registration Error: " . $e->getMessage());
            return redirect()->back()->with('error', 'Something went wrong during registration.')->withInput();
        }
    }

    public function login(Request $request)
    {
        Log::info("--- Login Process Started ---");
        Log::info("Request Data: ", $request->except(['password']));

        try {
            // 1. Validation
            Log::info("Step 1: Validating login fields...");
            $fields = $request->validate([
                'email' => 'required|string|email',
                'password' => 'required|string'
            ]);
            Log::info("Validation successful for email: " . $fields['email']);

            // 2. Verify email
            Log::info("Step 2: Checking user existence...");
            $user = User::where('email', $fields['email'])->first();

            // 3. Verify password
            Log::info("Step 3: Verifying password hash...");
            if (!$user || !Hash::check($fields['password'], $user->password)) {
                Log::warning("Login failed: Invalid credentials for email " . $fields['email']);

                return redirect()->back()->withErrors([
                    'email' => 'The provided credentials do not match our records.'
                ])->withInput();
            }

            // 4. Create token (Sanctum)
            Log::info("Step 4: Generating Sanctum token...");
            $token = $user->createToken('floodintel_token')->plainTextToken;
            Log::info("Generated Token for User {$user->email}: {$token}");

            Log::info("--- Login Process Completed: User ID {$user->id} ---");

            // Log the user into the local web session state for Blade testing
            auth()->login($user);

            return redirect()->route('dashboard')->with([
                'status' => 'Logged in successfully!',
                'auth_token' => $token
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error("Login Validation Failed: ", $e->errors());
            return redirect()->back()->withErrors($e->errors())->withInput();
        } catch (\Exception $e) {
            Log::error("Login Error: " . $e->getMessage());
            return redirect()->back()->with('error', 'Something went wrong during login.')->withInput();
        }
    }

    public function logout(Request $request)
    {
        Log::info("--- Logout Process Started ---");
        try {
            $user = $request->user();

            // 1. Revoke Sanctum Token kung meron
            if ($user) {
                Log::info("Step 1: Revoking current access token for User ID: {$user->id}");
                $user->currentAccessToken()->delete();
            }

            // 2. Linisin ang Stateful Web Session at Cookies
            Log::info("Step 2: Invalidating browser session cookie records...");
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            Log::info("--- Logout Process Completed Successfully ---");

            // ANG SULUSYON: Lagyan ng RETURN para ma-execute ang redirection sa browser!
            return redirect('/login')->with('status', 'Successfully logged out.');

        } catch (\Exception $e) {
            Log::error("Logout Error: " . $e->getMessage());
            return redirect('/login')->with('status', 'Successfully logged out.');
        }
    }
}
