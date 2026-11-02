<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\LoginEvent;
use App\Models\User;
use App\Support\DatabaseIdentity;
use App\Support\MailAvailability;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Authenticate the user and issue an API token.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        try {
            $request->authenticate();
        } catch (ValidationException $exception) {
            // The query builder issues no RETURNING, so the SELECT policy
            // (user_id = app_user_id()) does not apply to this write: a failed
            // attempt is recorded before any identity exists.
            DB::table('login_events')->insert([
                'outcome' => LoginEvent::OUTCOME_FAILED,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            throw $exception;
        }

        $user = $request->user();

        // The response embeds the policy-protected barangay, so this one read
        // runs under the identity the request middleware has not set yet.
        DatabaseIdentity::runAs($user->getKey(), fn () => $user->load('barangay'));

        $token = $user->createToken('auth_token');

        // The query builder issues no RETURNING, so the SELECT policy does not
        // apply to this write. The row is still written before the request
        // identity is set, so the INSERT policy is the permissive one.
        DB::table('login_events')->insert([
            'user_id' => $user->getKey(),
            'personal_access_token_id' => $token->accessToken->getKey(),
            'outcome' => LoginEvent::OUTCOME_SUCCESS,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'user' => new UserResource($user),
            'token' => $token->plainTextToken,
        ]);
    }

    /**
     * Get the authenticated user.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json(new UserResource($request->user()->load('barangay')));
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

        // A send that cannot deliver must not answer as if it had (fallback
        // row 17, rule 9).
        if (! MailAvailability::send(fn () => $request->user()->sendEmailVerificationNotification())) {
            return response()->json([
                'message' => 'The verification email could not be sent. Try again later.',
            ], 503);
        }

        return response()->json(['message' => 'Verification link sent.'], 202);
    }
}
