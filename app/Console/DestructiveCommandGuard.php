<?php

namespace App\Console;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\ConfigurationUrlParser;
use Symfony\Component\Console\Input\InputInterface;

final class DestructiveCommandGuard
{
    public const COMMANDS = ['db:wipe', 'migrate:fresh', 'migrate:refresh', 'migrate:reset'];

    private const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '::1'];

    private const POSTGRES_DRIVERS = ['pgsql', 'postgres', 'postgresql'];

    public function handle(CommandStarting $event): void
    {
        if (! in_array($event->command, self::COMMANDS, true)) {
            return;
        }

        if (config('database.allow_destructive')) {
            return;
        }

        $connection = $this->connectionName($event->input);
        $configuration = config('database.connections')[$connection] ?? null;

        if (! is_array($configuration)) {
            throw DestructiveCommandRefused::forUnknownConnection($event->command, $connection);
        }

        $resolved = (new ConfigurationUrlParser)->parseConfiguration($configuration);

        if (! $this->destinationIsDisposable($resolved)) {
            throw DestructiveCommandRefused::forDestination($event->command, $resolved);
        }
    }

    private function connectionName(InputInterface $input): string
    {
        $name = $input->getParameterOption('--database', null, true);

        return is_string($name) && $name !== '' ? $name : (string) config('database.default');
    }

    private function destinationIsDisposable(array $resolved): bool
    {
        $driver = $resolved['driver'] ?? null;

        if ($driver === 'sqlite') {
            return true;
        }

        if (! in_array($driver, self::POSTGRES_DRIVERS, true)) {
            return false;
        }

        $host = strtolower((string) ($resolved['host'] ?? ''));
        $database = strtolower((string) ($resolved['database'] ?? ''));

        return in_array($host, self::LOOPBACK_HOSTS, true)
            && (str_contains($database, 'test') || str_contains($database, 'scratch'));
    }
}
