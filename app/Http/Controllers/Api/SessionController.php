<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SessionResource;
use App\Models\LoginEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Laravel\Sanctum\PersonalAccessToken;

class SessionController extends Controller
{
    /**
     * List the authenticated user's active login sessions.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $successfulEvents = LoginEvent::query()
            ->where('user_id', $user->getKey())
            ->where('outcome', LoginEvent::OUTCOME_SUCCESS)
            ->whereNotNull('personal_access_token_id')
            ->orderByDesc('created_at')
            ->get();

        $tokens = $user->tokens()
            ->whereIn('id', $successfulEvents->pluck('personal_access_token_id')->unique()->all())
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->get()
            ->keyBy('id');

        $currentTokenId = null;

        $currentAccessToken = $user->currentAccessToken();

        if ($currentAccessToken instanceof PersonalAccessToken) {
            $currentTokenId = $currentAccessToken->getKey();
        } else {
            $bearerToken = $request->bearerToken();

            if (is_string($bearerToken) && str_contains($bearerToken, '|')) {
                $currentTokenId = (int) explode('|', $bearerToken, 2)[0];
            }
        }

        $sessions = $successfulEvents
            ->unique('personal_access_token_id')
            ->map(function (LoginEvent $event) use ($tokens, $currentTokenId) {
                $token = $tokens->get($event->personal_access_token_id);

                if ($token === null) {
                    return null;
                }

                return [
                    'id' => $token->getKey(),
                    'name' => $token->name,
                    'ip_address' => $event->ip_address,
                    'user_agent' => $event->user_agent,
                    'logged_in_at' => $event->created_at,
                    'last_used_at' => $token->last_used_at,
                    'is_current' => $token->getKey() === $currentTokenId,
                ];
            })
            ->filter()
            ->values();

        return SessionResource::collection($sessions);
    }
}
