<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        Log::info("--- API Register Process Started (Web/Mobile) ---");
        Log::info("Request Data: ", $request->except(['password', 'password_confirmation']));

        try {
            // Step 1: Input Validation
            Log::info("Step 1: Validating registration input fields...");
            $fields = $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|string|email|unique:users,email',
                'password' => 'required|string|min:8|confirmed'
            ]);
            Log::info("Validation successful for email: " . $fields['email']);

            // Step 2: Database Record Insertion
            Log::info("Step 2: Creating user profile inside database...");
            $user = User::create([
                'name' => $fields['name'],
                'email' => $fields['email'],
                'password' => Hash::make($fields['password']),
            ]);
            Log::info("User created successfully: ID {$user->id}");

            // Step 3: Sanctum Token Issuance
            Log::info("Step 3: Generating Sanctum plain text token...");
            $token = $user->createToken('floodintel_token')->plainTextToken;
            Log::info("Generated Token for User {$user->email}: {$token}");

            Log::info("--- API Register Process Completed Successfully ---");

            return response()->json([
                'success' => true,
                'message' => 'Account registered successfully!',
                'data' => [
                    'user' => $user,
                    'auth_token' => $token
                ]
            ], 201);

        } catch (ValidationException $e) {
            Log::error("Registration Validation Failed: ", $e->errors());
            return response()->json([
                'success' => false,
                'message' => 'The given data was invalid.',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            Log::error("Registration Error: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Something went wrong during registration.'
            ], 500);
        }
    }

    public function login(Request $request)
    {
        Log::info("--- API Login Process Started (Web/Mobile) ---");
        Log::info("Request Data: ", $request->except(['password']));

        try {
            // Step 1: Input Validation
            Log::info("Step 1: Validating login credentials...");
            $fields = $request->validate([
                'email' => 'required|string|email',
                'password' => 'required|string'
            ]);
            Log::info("Validation successful for email: " . $fields['email']);

            // Step 2: Fetch User Record
            Log::info("Step 2: Querying user identity match from database...");
            $user = User::where('email', $fields['email'])->first();

            // Step 3: Password Verification Check
            Log::info("Step 3: Verifying crypt password hash integration...");
            if (!$user || !Hash::check($fields['password'], $user->password)) {
                Log::warning("Login failed: Invalid credentials matching for email " . $fields['email']);
                return response()->json([
                    'success' => false,
                    'message' => 'The provided credentials do not match our records.'
                ], 401);
            }

            // Step 4: Sanctum Token Issuance
            Log::info("Step 4: Compiling dynamic application token access rights...");
            $token = $user->createToken('floodintel_token')->plainTextToken;
            Log::info("Generated Token for User {$user->email}: {$token}");

            Log::info("--- API Login Process Completed: User ID {$user->id} ---");

            return response()->json([
                'success' => true,
                'message' => 'Logged in successfully!',
                'data' => [
                    'user' => $user,
                    'auth_token' => $token
                ]
            ], 200);

        } catch (ValidationException $e) {
            Log::error("Login Validation Failed: ", $e->errors());
            return response()->json([
                'success' => false,
                'message' => 'The given data was invalid.',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            Log::error("Login Error: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Something went wrong during login.'
            ], 500);
        }
    }

    public function logout(Request $request)
    {
        Log::info("--- API Logout Process Started (Web/Mobile) ---");
        try {
            $user = $request->user();

            if ($user) {
                // Step 1: Revoke Current Bearer Access Token
                Log::info("Step 1: Revoking active access token database key for User ID: {$user->id}");
                $user->currentAccessToken()->delete();

                Log::info("--- API Logout Process Completed Successfully ---");

                return response()->json([
                    'success' => true,
                    'message' => 'Successfully logged out and token revoked.'
                ], 200);
            }

            Log::warning("Logout attempt failed: No authenticated user token found in request payload header.");
            return response()->json([
                'success' => false,
                'message' => 'Not authenticated.'
            ], 401);

        } catch (\Exception $e) {
            Log::error("Logout Error: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'An error occurred during logout.'
            ], 500);
        }
    }
}
