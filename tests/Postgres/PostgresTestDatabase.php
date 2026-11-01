<?php

namespace Tests\Postgres;

final class PostgresTestDatabase
{
    public static function name(string $url): ?string
    {
        if (str_contains($url, '://')) {
            $path = parse_url($url, PHP_URL_PATH);

            if (! is_string($path) || $path === '' || $path === '/') {
                return null;
            }

            return ltrim($path, '/');
        }

        if (preg_match('/(?:^|\s)dbname=([^\s]+)/i', $url, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    public static function isDisposable(?string $name, bool $allowNonTestDatabase = false): bool
    {
        if ($name === null || $name === '') {
            return false;
        }

        return $allowNonTestDatabase || str_contains(strtolower($name), 'test');
    }
}
