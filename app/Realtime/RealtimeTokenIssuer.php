<?php

namespace App\Realtime;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Issues the short-lived Supabase-compatible JWT a subscriber presents.
 *
 * Supabase Realtime reads the token's `sub` and evaluates the
 * realtime.messages policy with it; the token carries no barangay or scope,
 * because scope is decided from the database identity the `sub` names
 * (D-65).
 */
final class RealtimeTokenIssuer
{
    /**
     * @return array{token: string, expires_at: string}|null
     */
    public function issue(User $user): ?array
    {
        $settings = RealtimeSettings::fromConfig();

        if (! $settings->subscribable() || $settings->jwtSecret === null) {
            return null;
        }

        $issuedAt = now()->getTimestamp();
        $expiresAt = $issuedAt + $settings->tokenTtl;

        $header = self::encode(['alg' => 'HS256', 'typ' => 'JWT']);
        $claims = self::encode([
            'sub' => (string) $user->getKey(),
            'role' => 'authenticated',
            'aud' => 'authenticated',
            'iat' => $issuedAt,
            'exp' => $expiresAt,
        ]);

        $signature = self::base64Url(
            hash_hmac('sha256', $header.'.'.$claims, $settings->jwtSecret, true),
        );

        return [
            'token' => $header.'.'.$claims.'.'.$signature,
            'expires_at' => CarbonImmutable::createFromTimestamp($expiresAt)->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private static function encode(array $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES);

        return self::base64Url((string) $json);
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
