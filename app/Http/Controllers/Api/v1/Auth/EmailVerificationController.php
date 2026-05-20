<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Foundation\Auth\EmailVerificationRequest;

class EmailVerificationController extends Controller
{
    // Resend verification link
    public function sendVerificationNotification(Request $request)
    {
        // Step 1: Check kung verified na
        if ($request->user()->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email already verified.'], 400);
        }

        $request->user()->sendEmailVerificationNotification();

        Log::info("Step 1: Verification email resent for User ID: " . $request->user()->id);

        return response()->json(['message' => 'Verification link sent!'], 200);
    }

    // Verify email (usually triggered via clicking the link in email)
    public function verifyEmail(Request $request, $id, $hash)
    {
        // Sa API, kailangan natin ang user model para ma-check ang hash
        $user = \App\Models\User::findOrFail($id);

        if (!hash_equals((string) $hash, sha1($user->getEmailForVerification()))) {
            return response()->json(['message' => 'Invalid verification link.'], 403);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email already verified.'], 200);
        }

        if ($user->markEmailAsVerified()) {
            Log::info("Step 1: Email verified successfully for User ID: " . $user->id);
            return response()->json(['message' => 'Email verified successfully!'], 200);
        }

        return response()->json(['message' => 'Failed to verify email.'], 500);
    }
}
