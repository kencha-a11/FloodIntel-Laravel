<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\DB;
use Throwable;

class EmailVerificationController extends Controller
{
    public function sendVerificationNotification(Request $request)
    {
        $user = $request->user();
        if (!$user)
            return response()->json(['message' => 'Unauthenticated'], 401);

        $throttleKey = 'resend-verification:' . $user->id;
        if (RateLimiter::tooManyAttempts($throttleKey, 2)) {
            return response()->json(['message' => 'Too many requests. Please wait.'], 429);
        }

        $lock = null;
        $locked = false;

        // 1. Safe Lock Acquisition (Covering all potential driver failures)
        try {
            $lock = Cache::lock('resend-lock:' . $user->id, 15);
            if ($lock->get()) {
                $locked = true;
            } else {
                return response()->json(['message' => 'Request in progress.'], 409);
            }
        } catch (Throwable $e) {
            Log::warning('Lock acquisition failed, proceeding without lock.', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
            // Fallback: Continue execution even if locking fails
        }

        // 2. Main Logic Execution
        try {
            if ($user->hasVerifiedEmail()) {
                return response()->json(['message' => 'Email already verified.'], 200);
            }

            if (empty($user->email)) {
                return response()->json(['message' => 'User has no email address set.'], 400);
            }

            $user->sendEmailVerificationNotification();
            RateLimiter::hit($throttleKey, 60);

            Log::info("[SEND-VERIFICATION] Verification email sent", ['user_id' => $user->id]);
            return response()->json(['message' => 'Verification link sent!'], 200);

        } catch (Throwable $e) {
            Log::error("[SEND-VERIFICATION] Failed to send email", [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
            return response()->json(['message' => 'Failed to send email.'], 500);

        } finally {
            // 3. Safe Lock Release (Only if acquired)
            if ($locked && $lock) {
                try {
                    $lock->release();
                } catch (Throwable $e) {
                    Log::error('Lock release failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
                }
            }
        }
    }

    public function verifyEmail($id, $hash, Request $request)
    {
        $user = User::find($id);

        // I-convert ang lahat ng returns sa JSON
        if (!$user || !URL::hasValidSignature($request) || !hash_equals(sha1($user->getEmailForVerification()), (string) $hash)) {
            Log::warning("[VERIFY-EMAIL] Invalid verification attempt for User ID: {$id}");
            return response()->json(['message' => 'Invalid or expired verification link.'], 400);
        }

        if (!$user->hasVerifiedEmail()) {
            DB::table('users')->where('id', $user->id)->update(['email_verified_at' => now()]);
            Log::info("[VERIFY-EMAIL] Successfully verified user: {$id}");
        }

        return response()->json(['message' => 'Email verified successfully.'], 200);
    }
}
