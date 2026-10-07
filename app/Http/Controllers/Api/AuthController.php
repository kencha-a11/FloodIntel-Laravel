<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    /**
     * Register a new user and issue an API token.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create($request->validated());

        event(new Registered($user));

        return response()->json([
            'user' => $user,
            'token' => $user->createToken('auth_token')->plainTextToken,
        ], 201);
    }

    /**
     * Authenticate the user and issue an API token.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $request->authenticate();

        $user = $request->user();

        return response()->json([
            'user' => $user,
            'token' => $user->createToken('auth_token')->plainTextToken,
        ]);
    }

    /**
     * Get the authenticated user.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json($request->user());
    }

    /**
     * Revoke the user's current API token.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json(null, 204);
    }

    /**
     * Mark the user's email address as verified from a signed link.
     */
    public function verifyEmail(Request $request, int $id, string $hash): JsonResponse|RedirectResponse
    {
        $user = User::findOrFail($id);

        if (! hash_equals($hash, sha1($user->getEmailForVerification()))) {
            return $this->verificationFailed($request);
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();

            event(new Verified($user));
        }

        $frontendUrl = config('app.frontend_url');

        if ($request->wantsJson() || blank($frontendUrl)) {
            return response()->json(['message' => 'Email verified successfully.']);
        }

        return redirect()->away($frontendUrl.'?email=verified');
    }

    /**
     * Send the user back to the frontend when verification fails.
     */
    protected function verificationFailed(Request $request): JsonResponse|RedirectResponse
    {
        $frontendUrl = config('app.frontend_url');

        if ($request->wantsJson() || blank($frontendUrl)) {
            abort(403);
        }

        return redirect()->away($frontendUrl.'?email=verification-failed');
    }

    /**
     * Instruct an unverified user to check their inbox.
     */
    public function verificationNotice(): JsonResponse
    {
        return response()->json(['message' => 'Your email address is not verified.']);
    }

    /**
     * Resend the email verification notification.
     */
    public function resendVerification(Request $request): JsonResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email already verified.'], 409);
        }

        $request->user()->sendEmailVerificationNotification();

        return response()->json(['message' => 'Verification link sent.'], 202);
    }
}
