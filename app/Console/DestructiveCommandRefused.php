<?php

namespace App\Console;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

final class DestructiveCommandRefused extends RuntimeException implements ShouldntReport
{
    public static function forDestination(string $command, array $resolved): self
    {
        return new self(sprintf(
            'Refusing [%s]: the resolved destination [%s] is not disposable. '
            .'Set DB_URL="" and use SQLite or a loopback test/scratch database, '
            .'or set DB_ALLOW_DESTRUCTIVE=1 deliberately. See CASE-004 and verification P3.',
            $command,
            self::describe($resolved),
        ));
    }

    public static function forUnknownConnection(string $command, string $connection): self
    {
        return new self(sprintf(
            'Refusing [%s]: the connection [%s] is not configured, so its destination cannot be verified as disposable.',
            $command,
            $connection,
        ));
    }

    private static function describe(array $resolved): string
    {
        return sprintf(
            '%s %s/%s',
            $resolved['driver'] ?? 'unknown',
            $resolved['host'] ?? 'local',
            $resolved['database'] ?? 'unknown',
        );
    }
}
