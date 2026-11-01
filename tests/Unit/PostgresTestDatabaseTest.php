<?php

use Tests\Postgres\PostgresTestDatabase;

it('reads a database name from a postgres URL', function () {
    expect(PostgresTestDatabase::name('postgresql://postgres@127.0.0.1:55432/floodintel_test'))
        ->toBe('floodintel_test');
});

it('reads a database name from a key-value DSN', function () {
    expect(PostgresTestDatabase::name('host=127.0.0.1 port=55432 dbname=floodintel_test'))
        ->toBe('floodintel_test');
});

it('returns null when the DSN carries no database name', function (string $url) {
    expect(PostgresTestDatabase::name($url))->toBeNull();
})->with([
    'empty' => '',
    'bare host' => 'postgresql://postgres@127.0.0.1:55432',
    'root path' => 'postgresql://postgres@127.0.0.1:55432/',
    'no database parameter' => 'host=127.0.0.1 port=55432',
]);

it('accepts only database names that mark themselves as disposable', function (string $name, bool $allowed) {
    expect(PostgresTestDatabase::isDisposable($name))->toBe($allowed);
})->with([
    'test suffix' => ['floodintel_test', true],
    'test prefix' => ['test_floodintel', true],
    'mixed case' => ['FloodIntelTEST', true],
    'production name' => ['floodintel', false],
    'empty' => ['', false],
]);

it('accepts a non-test name only with an explicit override', function () {
    expect(PostgresTestDatabase::isDisposable('floodintel', true))->toBeTrue()
        ->and(PostgresTestDatabase::isDisposable(null, true))->toBeFalse();
});
