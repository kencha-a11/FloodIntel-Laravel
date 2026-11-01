<?php

use App\Console\DestructiveCommandRefused;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;

function guardCommandStarting(string $command, ?string $database = null): CommandStarting
{
    $definition = new InputDefinition([new InputOption('database')]);
    $parameters = $database === null ? [] : ['--database' => $database];

    return new CommandStarting(
        $command,
        new ArrayInput($parameters, $definition),
        new NullOutput,
    );
}

function guardRemotePostgres(): array
{
    return [
        'driver' => 'pgsql',
        'url' => null,
        'host' => 'aws-0-ap-southeast-1.pooler.supabase.com',
        'port' => 5432,
        'database' => 'postgres',
        'username' => 'app_runtime',
        'password' => 'secret',
    ];
}

it('refuses every destructive command aimed at a remote Postgres host', function (string $command) {
    config([
        'database.default' => 'pgsql',
        'database.connections.pgsql' => guardRemotePostgres(),
        'database.allow_destructive' => false,
    ]);

    expect(fn () => Event::dispatch(guardCommandStarting($command)))
        ->toThrow(DestructiveCommandRefused::class, 'pooler.supabase.com');
})->with(['db:wipe', 'migrate:fresh', 'migrate:refresh', 'migrate:reset']);

it('resolves the destination from the connection URL rather than the host field', function () {
    config([
        'database.default' => 'pgsql',
        'database.allow_destructive' => false,
        'database.connections.pgsql' => [
            'driver' => 'pgsql',
            'url' => 'postgresql://app_runtime:secret@aws-0-ap-southeast-1.pooler.supabase.com:5432/postgres',
            'host' => '127.0.0.1',
            'database' => 'floodintel_test',
        ],
    ]);

    expect(fn () => Event::dispatch(guardCommandStarting('db:wipe')))
        ->toThrow(DestructiveCommandRefused::class, 'pooler.supabase.com');
});

it('allows a destructive command on the in-memory SQLite connection', function () {
    config([
        'database.default' => 'sqlite',
        'database.allow_destructive' => false,
        'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => ':memory:'],
    ]);

    expect(fn () => Event::dispatch(guardCommandStarting('migrate:fresh')))
        ->not->toThrow(DestructiveCommandRefused::class);
});

it('allows a loopback Postgres connection whose database name marks it disposable', function () {
    config([
        'database.default' => 'sqlite',
        'database.allow_destructive' => false,
        'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => ':memory:'],
        'database.connections.pgsql_test' => [
            'driver' => 'pgsql',
            'url' => null,
            'host' => '127.0.0.1',
            'port' => 55432,
            'database' => 'floodintel_test',
        ],
    ]);

    expect(fn () => Event::dispatch(guardCommandStarting('migrate:fresh', 'pgsql_test')))
        ->not->toThrow(DestructiveCommandRefused::class);
});

it('does not interfere with non-destructive commands on a remote host', function () {
    config([
        'database.default' => 'pgsql',
        'database.connections.pgsql' => guardRemotePostgres(),
    ]);

    expect(fn () => Event::dispatch(guardCommandStarting('migrate')))
        ->not->toThrow(DestructiveCommandRefused::class);
});

it('allows a non-disposable destination only under the explicit override', function () {
    config([
        'database.default' => 'pgsql',
        'database.connections.pgsql' => guardRemotePostgres(),
        'database.allow_destructive' => true,
    ]);

    expect(fn () => Event::dispatch(guardCommandStarting('migrate:fresh')))
        ->not->toThrow(DestructiveCommandRefused::class);
});

it('refuses a real db:wipe invocation through the console kernel', function () {
    // Laravel skips Symfony's command-event reroute while running unit tests,
    // so the kernel path is enabled here; the listener itself comes from AppServiceProvider.
    $this->app->make(ConsoleKernel::class)->rerouteSymfonyCommandEvents();

    config([
        'database.default' => 'pgsql',
        'database.allow_destructive' => false,
        // A non-resolvable host: if the guard ever regresses, the connection attempt dies at DNS.
        'database.connections.pgsql' => [
            'driver' => 'pgsql',
            'url' => null,
            'host' => 'db.example.invalid',
            'port' => 5432,
            'database' => 'postgres',
            'username' => 'app_runtime',
            'password' => 'secret',
        ],
    ]);

    expect(fn () => Artisan::call('db:wipe', ['--database' => 'pgsql']))
        ->toThrow(DestructiveCommandRefused::class, 'db.example.invalid');
});

it('refuses a destructive command whose connection is not configured', function () {
    config([
        'database.default' => 'sqlite',
        'database.allow_destructive' => false,
    ]);

    expect(fn () => Event::dispatch(guardCommandStarting('db:wipe', 'missing')))
        ->toThrow(DestructiveCommandRefused::class, 'missing');
});
