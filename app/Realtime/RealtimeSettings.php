<?php

namespace App\Realtime;

/**
 * The Supabase Realtime settings read from config.
 *
 * Publishing needs the URL and the service-role key; subscribing needs the
 * URL, the anon key, and the JWT secret. Realtime is enabled only when all of
 * them exist, so a half-configured deployment keeps the polling substitute
 * instead of failing halfway (D-63, D-66).
 */
final class RealtimeSettings
{
    private function __construct(
        public readonly ?string $url,
        public readonly ?string $anonKey,
        public readonly ?string $serviceRoleKey,
        public readonly ?string $jwtSecret,
        public readonly int $tokenTtl,
        public readonly int $broadcastTimeout,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            self::url(config('services.supabase.url')),
            self::string(config('services.supabase.anon_key')),
            self::string(config('services.supabase.service_role_key')),
            self::string(config('services.supabase.jwt_secret')),
            max(60, (int) config('services.supabase.realtime_token_ttl', 600)),
            max(1, (int) config('services.supabase.broadcast_timeout', 5)),
        );
    }

    public function publishable(): bool
    {
        return $this->url !== null && $this->serviceRoleKey !== null;
    }

    public function subscribable(): bool
    {
        return $this->url !== null && $this->anonKey !== null && $this->jwtSecret !== null;
    }

    public function enabled(): bool
    {
        return $this->publishable() && $this->subscribable();
    }

    private static function string(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * A Supabase project API URL is a bare https origin (the realtime and REST
     * endpoints hang off `/realtime/...` and `/rest/...`). A dashboard URL such
     * as `https://supabase.com/dashboard/project/<ref>` carries a path and is
     * not an API endpoint; treating it as configured would hand the client a
     * doomed websocket URL. Reject anything that is not a clean origin so a
     * misconfigured deployment keeps the polling substitute (D-63, D-66).
     */
    private static function url(mixed $value): ?string
    {
        $value = self::string($value);

        if ($value === null) {
            return null;
        }

        $parts = parse_url($value);

        if (! is_array($parts)) {
            return null;
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? '';
        $path = $parts['path'] ?? '';

        if ($scheme !== 'https' || $host === '' || ($path !== '' && $path !== '/')) {
            return null;
        }

        return $value;
    }
}
