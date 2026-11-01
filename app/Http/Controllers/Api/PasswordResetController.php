<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use App\Support\MailAvailability;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class PasswordResetController extends Controller
{
    /**
     * Email a password reset link to the user.
     */
    public function sendResetLink(ForgotPasswordRequest $request): JsonResponse
    {
        $status = null;

        // A send that cannot deliver must not answer as if it had (fallback
        // row 17, rule 9).
        $sent = MailAvailability::send(function () use ($request, &$status): void {
            $status = Password::broker()->sendResetLink($request->only('email'));
        });

        if (! $sent) {
            return response()->json([
                'message' => 'The reset email could not be sent. Try again later.',
            ], 503);
        }

        if ($status === Password::RESET_THROTTLED) {
            return response()->json([
                'message' => 'Please wait before retrying.',
            ], 429);
        }

        return response()->json([
            'message' => 'If that email address is registered, we have emailed a password reset link.',
        ]);
    }

    /**
     * Reset the user's password using a valid reset token.
     */
    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::broker()->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                $user->tokens()->delete();

                event(new PasswordReset($user));
            },
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json(['message' => 'Your password has been reset.']);
        }

        return response()->json(['message' => 'This password reset token is invalid.'], 422);
    }
}
