<?php

use Illuminate\Database\ConfigurationUrlParser;

function withDatabaseUrl(string $url, Closure $callback): mixed
{
    $hadServerValue = array_key_exists('DB_URL', $_SERVER);
    $hadEnvValue = array_key_exists('DB_URL', $_ENV);
    $previousServerValue = $_SERVER['DB_URL'] ?? null;
    $previousEnvValue = $_ENV['DB_URL'] ?? null;

    $_SERVER['DB_URL'] = $url;
    $_ENV['DB_URL'] = $url;
    putenv('DB_URL='.$url);

    try {
        return $callback();
    } finally {
        if ($hadServerValue) {
            $_SERVER['DB_URL'] = $previousServerValue;
        } else {
            unset($_SERVER['DB_URL']);
        }

        if ($hadEnvValue) {
            $_ENV['DB_URL'] = $previousEnvValue;
        } else {
            unset($_ENV['DB_URL']);
        }

        putenv('DB_URL');
    }
}

it('never lets a Postgres database URL retarget the sqlite connection', function () {
    $sqlite = withDatabaseUrl(
        'postgresql://app_runtime:secret@aws-0-ap-southeast-1.pooler.supabase.com:5432/postgres',
        fn (): array => (require config_path('database.php'))['connections']['sqlite'],
    );

    expect($sqlite)
        ->not->toHaveKey('url')
        ->and($sqlite['driver'])->toBe('sqlite');
});

it('resolves the sqlite connection as sqlite when the database URL is loaded', function () {
    $resolved = withDatabaseUrl(
        'postgresql://app_runtime:secret@aws-0-ap-southeast-1.pooler.supabase.com:5432/postgres',
        fn (): array => (new ConfigurationUrlParser)->parseConfiguration(
            (require config_path('database.php'))['connections']['sqlite'],
        ),
    );

    expect($resolved['driver'])->toBe('sqlite');
});
