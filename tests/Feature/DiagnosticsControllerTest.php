<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    config([
        'diagnostics.key' => str_repeat('test-only-', 4),
        'diagnostics.expires_at' => '2030-01-01T00:00:00+00:00',
        'diagnostics.path' => sys_get_temp_dir().'/diagnostics-'.bin2hex(random_bytes(8)).'/laravel.log',
        'diagnostics.cache_path' => sys_get_temp_dir().'/diagnostics-cache-'.bin2hex(random_bytes(8)),
    ]);
});

afterEach(function () {
    File::deleteDirectory(dirname(config('diagnostics.path')));
    File::deleteDirectory(config('diagnostics.cache_path'));
});

function diagnosticFixture(string $contents): void
{
    File::ensureDirectoryExists(dirname(config('diagnostics.path')));
    file_put_contents(config('diagnostics.path'), $contents);
}

test('the web route renders only an empty unlock screen without credentials', function (string $path) {
    diagnosticFixture("[2026-11-17 12:00:00] production.ERROR: private-fixture-marker\n");

    $this->get($path)->assertSee('Unlock this session')->assertDontSee('private-fixture-marker')
        ->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Cache-Control', 'no-store, private');
})->with(['/diagnostics/logs']);

test('the web route rejects missing or incorrect header credentials with 403', function (string $path, string $key) {
    $this->getJson($path, ['X-Diagnostics-Key' => $key])->assertForbidden()
        ->assertExactJson(['message' => 'Diagnostics access denied.']);
})->with(['/diagnostics/logs'])->with(['', 'incorrect']);

test('removed diagnostic URLs return not found even with valid credentials', function (string $method, string $path) {
    $this->json($method, $path, [], ['X-Diagnostics-Key' => config('diagnostics.key')])->assertNotFound();
})->with([
    ['GET', '/api/diagnostics/logs'],
    ['GET', '/api/diagnostics/logs-nokey'],
    ['POST', '/api/diagnostics/logs-clear'],
    ['GET', '/diagnostics/logs-nokey'],
]);

test('query credentials cannot authorize diagnostic reads and are not echoed', function () {
    $this->getJson('/diagnostics/logs?key='.config('diagnostics.key'))->assertForbidden()
        ->assertDontSee(config('diagnostics.key'));
});

test('invalid or missing expiry fails closed with 503', function (string $expiry) {
    config(['diagnostics.expires_at' => $expiry]);

    $this->getJson('/diagnostics/logs', ['X-Diagnostics-Key' => config('diagnostics.key')])
        ->assertServiceUnavailable()->assertExactJson(['message' => 'Diagnostics access is not configured.']);
})->with(['', 'tomorrow', '2030-01-01T00:00:00', '2030-02-30T00:00:00+00:00']);

test('missing or short credentials fail closed with 503', function (string $key) {
    config(['diagnostics.key' => $key]);

    $this->getJson('/diagnostics/logs', ['X-Diagnostics-Key' => $key])->assertServiceUnavailable();
})->with(['', 'too-short']);

test('access expires at the configured instant with 403', function () {
    $this->travelTo(new DateTimeImmutable('2030-01-01T00:00:00+00:00'));

    $this->getJson('/diagnostics/logs', ['X-Diagnostics-Key' => config('diagnostics.key')])
        ->assertForbidden()->assertExactJson(['message' => 'Diagnostics access has expired.']);
});

test('authorized web requests return sanitized synthetic events without using the database', function (string $path) {
    diagnosticFixture("[2026-11-17 12:00:00] production.ERROR: Synthetic exception\n#0 example.php(1): action()\n");
    DB::connection()->beforeExecuting(function () {
        throw new RuntimeException('The database is unavailable.');
    });
    config(['cache.default' => 'database', 'session.driver' => 'database']);

    $this->getJson($path, ['X-Diagnostics-Key' => config('diagnostics.key')])->assertOk()
        ->assertJsonPath('events.0.message', 'Synthetic exception')->assertJsonPath('state', 'ready')
        ->assertJsonPath('expires_at', '2030-01-01T00:00:00+00:00')
        ->assertHeader('Cache-Control', 'no-store, private');
})->with(['/diagnostics/logs']);

test('clearing remains forbidden and preserves evidence even with a valid credential', function (string $key) {
    diagnosticFixture("preserve-this-fixture\n");

    $this->postJson('/diagnostics/logs-clear', [], ['X-Diagnostics-Key' => $key])->assertForbidden();

    expect(file_get_contents(config('diagnostics.path')))->toBe("preserve-this-fixture\n");
})->with(['', str_repeat('test-only-', 4)]);

test('both diagnostics web routes share a file backed 30 per minute limit and recover after expiry', function () {
    $this->freezeTime();
    for ($i = 0; $i < 30; $i++) {
        $this->getJson('/diagnostics/logs')->assertForbidden();
    }

    $this->postJson('/diagnostics/logs-clear')->assertTooManyRequests()->assertHeader('Retry-After', '60');
    $this->getJson('/diagnostics/logs')->assertTooManyRequests();
    $this->travel(61)->seconds();
    $this->getJson('/diagnostics/logs')->assertForbidden();
});

test('unavailable local limiter fails closed with generic 503 instead of falling back to a database', function () {
    diagnosticFixture('not-a-directory');
    config(['diagnostics.cache_path' => config('diagnostics.path')]);

    $this->getJson('/diagnostics/logs', ['X-Diagnostics-Key' => config('diagnostics.key')])
        ->assertServiceUnavailable()->assertExactJson(['message' => 'Diagnostics are temporarily unavailable.']);
});

test('production access requires HTTPS with 403 on an insecure request', function () {
    app()->instance('env', 'production');

    $this->getJson('/diagnostics/logs', ['X-Diagnostics-Key' => config('diagnostics.key')])
        ->assertForbidden()->assertExactJson(['message' => 'Diagnostics require HTTPS.']);
});

test('production access accepts HTTPS without database sessions', function () {
    app()->instance('env', 'production');

    $this->getJson('https://localhost/diagnostics/logs', ['X-Diagnostics-Key' => config('diagnostics.key')])
        ->assertOk()->assertJsonPath('state', 'missing');
});
