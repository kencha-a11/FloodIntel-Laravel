<?php

require __DIR__.'/../vendor/autoload.php';

/*
| The Postgres suite is opt-in. DB_POSTGRES_TEST_URL is deliberately separate
| from DB_URL so a developer's .env cannot be picked up by accident; when the
| variable is absent the application falls back to the in-memory SQLite path
| and every test in tests/Postgres skips before booting.
*/
$url = getenv('DB_POSTGRES_TEST_URL') ?: '';

$values = $url === ''
    ? [
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => ':memory:',
        'DB_URL' => '',
    ]
    : [
        'DB_CONNECTION' => 'pgsql',
        'DB_URL' => $url,
    ];

foreach ($values as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}
