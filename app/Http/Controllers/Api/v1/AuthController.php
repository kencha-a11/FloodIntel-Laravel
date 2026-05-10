<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log; // Inimport natin ito

class AuthController extends Controller
{
    public function register(Request $request)
    {
        Log::info("--- Register Process Started ---");
        Log::info("Request Data: ", $request->all());

        try {
            // 1. Validation
            Log::info("Step 1: Validating input fields...");
            $fields = $request->validate([
                'name' => 'required|string',
                'email' => 'required|string|unique:users,email',
                'password' => 'required|string|confirmed'
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

            return response()->json([
                'message' => 'User registered successfully',
                'user' => $user,
                'token' => $token
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error("Validation Failed: ", $e->errors());
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error("Registration Error: " . $e->getMessage());
            return response()->json([
                'message' => 'Something went wrong during registration.'
            ], 500);
        }
    }

    public function login(Request $request)
    {
        Log::info("--- Login Process Started ---");
        // I-log natin lahat ng pumasok para makita kung empty ba talaga sa side ng server
        Log::info("Request Data: ", $request->except(['password']));

        try {
            // 1. Validation
            Log::info("Step 1: Validating login fields...");
            $fields = $request->validate([
                'email' => 'required|string',
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
                return response()->json([
                    'message' => 'Invalid credentials'
                ], 401);
            }

            // 4. Create token (Sanctum)
            Log::info("Step 4: Generating Sanctum token...");
            $token = $user->createToken('floodintel_token')->plainTextToken;
            Log::info("Generated Token for User {$user->email}: {$token}");

            Log::info("--- Login Process Completed: User ID {$user->id} ---");

            return response()->json([
                'message' => 'Logged in successfully',
                'user' => $user,
                'token' => $token
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error("Login Validation Failed: ", $e->errors());
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error("Login Error: " . $e->getMessage());
            return response()->json([
                'message' => 'Something went wrong during login.'
            ], 500);
        }
    }

    public function logout(Request $request)
    {
        Log::info("--- Logout Process Started ---");

        try {
            $user = $request->user();
            $userId = $user->id;

            Log::info("Step 1: Revoking token for User ID: {$userId}");
            $user->currentAccessToken()->delete();

            Log::info("--- Logout Process Completed: User ID {$userId} ---");

            return response()->json([
                'message' => 'Successfully logged out'
            ], 200);

        } catch (\Exception $e) {
            Log::error("Logout Error: " . $e->getMessage());
            return response()->json([
                'message' => 'Error during logout.'
            ], 500);
        }
    }
}
