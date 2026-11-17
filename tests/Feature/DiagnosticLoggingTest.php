<?php

use App\Logging\SanitizeDiagnosticRecord;
use App\Services\DiagnosticLogReader;
use Illuminate\Database\QueryException;
use Illuminate\Log\LogManager;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

beforeEach(function () {
    $this->diagnosticDirectories = [];
});

afterEach(function () {
    foreach ($this->diagnosticDirectories as $directory) {
        File::deleteDirectory($directory);
    }
});

function diagnosticDirectory(TestCase $test): string
{
    $directory = tempnam(sys_get_temp_dir(), 'diagnostic-test-');
    unlink($directory);
    mkdir($directory);
    $directory = str_replace('\\', '/', $directory);
    $test->diagnosticDirectories[] = $directory;

    config([
        'diagnostics.path' => $directory.'/laravel.log',
        'logging.channels.single.path' => $directory.'/laravel.log',
        'logging.channels.daily.path' => $directory.'/laravel.log',
        'logging.channels.monthly.path' => $directory.'/laravel.log',
        'logging.channels.stderr.handler_with.stream' => $directory.'/stderr.log',
        'logging.channels.emergency.path' => $directory.'/emergency.log',
    ]);

    return $directory;
}

/** @param array<string, mixed> $overrides */
function diagnosticJson(string $message, array $overrides = []): string
{
    return json_encode(array_replace([
        'datetime' => '2026-11-17T12:00:00+00:00',
        'level_name' => 'ERROR',
        'message' => $message,
    ], $overrides), JSON_THROW_ON_ERROR)."\n";
}

it('boots only the isolated testing environment', function () {
    expect($this->app->environmentFile())->toBe('.env.testing');
    expect(config('database.default'))->toBe('sqlite');
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    expect(config('database.connections.sqlite.url'))->toBeNull();
});

it('defaults to stderr and daily while retaining an explicit stack override', function () {
    $repository = Env::getRepository();
    $original = $repository->get('LOG_STACK');

    try {
        $repository->clear('LOG_STACK');
        $fallback = require config_path('logging.php');
        $repository->set('LOG_STACK', 'single,stderr');
        $explicit = require config_path('logging.php');

        expect($fallback['channels']['stack'])->toMatchArray([
            'channels' => ['stderr', 'daily'],
            'ignore_exceptions' => true,
        ]);
        expect($explicit['channels']['stack']['channels'])->toBe(['single', 'stderr']);
    } finally {
        $original === null ? $repository->clear('LOG_STACK') : $repository->set('LOG_STACK', $original);
    }
});

it('redacts messages and context before each local sink stores JSON', function (string $channel) {
    diagnosticDirectory($this);
    config(['diagnostics.release' => 'v1.2.3', 'diagnostics.instance' => 'worker-2']);
    Context::add('request_body', ['private' => 'extra-body-value']);
    $exception = new QueryException(
        'pgsql',
        'select * from users where email = ? and password = ?',
        ['fixture@example.test', 'binding-secret'],
        new RuntimeException('SQLSTATE failure postgres://name:uri-secret@host/db'),
    );
    $logger = (new LogManager($this->app))->channel($channel);
    $path = $logger->getHandlers()[0]->getUrl();

    $logger->error($exception->getMessage(), [
        'request_id' => '981987b6-a181-4c9a-b5d3-291d64ddc8e1',
        'exception' => $exception,
        'status' => 500,
        'body' => ['arbitrary' => 'body-secret'],
        'credentials' => 'context-secret',
        'custom_field' => 'unrecognized-secret',
        'user_id' => 91234,
    ]);
    $logger->getLogger()->close();

    $stored = file_get_contents($path);
    $record = json_decode($stored, true, 32, JSON_THROW_ON_ERROR);
    expect($stored)->not->toContain(
        'fixture@example.test', 'binding-secret', 'uri-secret', 'body-secret',
        'context-secret', 'unrecognized-secret', 'extra-body-value', '91234', 'select *',
    );
    expect($record['message'])->toBe('[redacted sensitive diagnostic text]');
    expect($record['context']['exception']['class'])->toBe(QueryException::class);
    expect($record['context']['exception']['trace'])->not->toBeEmpty();
    expect($record['context']['request_id'])->toBe('981987b6-a181-4c9a-b5d3-291d64ddc8e1');
    expect($record['extra'])->toMatchArray(['release' => 'v1.2.3', 'instance' => 'worker-2']);
    expect($record['extra']['event_id'])->toBeUuid();
})->with(['single', 'daily', 'monthly', 'stderr']);

it('does not stringify untrusted context during placeholder interpolation', function () {
    $directory = diagnosticDirectory($this);
    $logger = (new LogManager($this->app))->channel('single');
    $value = new class implements Stringable
    {
        public function __toString(): string
        {
            throw new RuntimeException('Untrusted context must not be stringified');
        }
    };

    $logger->error('Operation failed {arbitrary}', ['arbitrary' => $value]);
    $logger->getLogger()->close();

    $record = json_decode(file_get_contents($directory.'/laravel.log'), true, 32, JSON_THROW_ON_ERROR);
    expect($record['message'])->toBe('[redacted sensitive diagnostic text]');
    expect($record['context'])->toBe([]);
});

it('keeps the stderr surrogate writing after a preceding daily sink fails', function () {
    $directory = diagnosticDirectory($this);
    file_put_contents($directory.'/blocked', 'not a directory');
    config([
        'logging.channels.stack.channels' => ['daily', 'stderr'],
        'logging.channels.daily.path' => $directory.'/blocked/laravel.log',
    ]);
    $logger = (new LogManager($this->app))->channel('stack');

    $logger->error('Local sink unavailable', ['password' => 'must-not-reach-stderr']);
    $logger->getLogger()->close();

    $stored = file_get_contents($directory.'/stderr.log');
    expect($stored)->not->toContain('must-not-reach-stderr');
    expect(json_decode($stored, true, 32, JSON_THROW_ON_ERROR)['message'])->toBe('Local sink unavailable');
    expect(file_exists($directory.'/emergency.log'))->toBeFalse();
});

it('shares one safe event identity across stack sinks', function () {
    $directory = diagnosticDirectory($this);
    config(['logging.channels.stack.channels' => ['single', 'stderr']]);
    $logger = (new LogManager($this->app))->channel('stack');

    $logger->warning('Worker restarted');
    $logger->getLogger()->close();

    $file = json_decode(file_get_contents($directory.'/laravel.log'), true, 32, JSON_THROW_ON_ERROR);
    $stderr = json_decode(file_get_contents($directory.'/stderr.log'), true, 32, JSON_THROW_ON_ERROR);
    expect($file['extra']['event_id'])->toBeUuid()->toBe($stderr['extra']['event_id']);
});

it('omits unsafe release and instance labels and untrusted correlation identifiers', function () {
    $directory = diagnosticDirectory($this);
    config(['diagnostics.release' => 'token=deployment-secret', 'diagnostics.instance' => '192.0.2.5']);
    $logger = (new LogManager($this->app))->channel('single');

    $logger->info('Worker started', ['request_id' => 'fixture@example.test']);
    $logger->getLogger()->close();

    $record = json_decode(file_get_contents($directory.'/laravel.log'), true, 32, JSON_THROW_ON_ERROR);
    expect(array_keys($record['extra']))->toBe(['event_id']);
    expect($record['context'])->toBe([]);
});

it('reuses conservative text redaction for common sensitive forms', function (string $input, string $secret) {
    expect(SanitizeDiagnosticRecord::text($input))->not->toContain($secret);
})->with([
    'bearer' => ['Authorization: Bearer bearer-value', 'bearer-value'],
    'cookie' => ['Cookie: session=cookie-value', 'cookie-value'],
    'URI' => ['Failed redis://name:uri-value@localhost/0', 'uri-value'],
    'query' => ['Failed /callback?code=query-value&state=abc', 'query-value'],
    'email' => ['Delivery failed for fixture@example.test', 'fixture@example.test'],
    'IPv4' => ['Peer 192.0.2.12 failed', '192.0.2.12'],
    'IPv6' => ['Peer 2001:db8::1234 failed', '2001:db8::1234'],
    'SQL' => ["select * from accounts where name = 'sql-value'", 'sql-value'],
    'unknown JSON key' => ['Failure {"custom":"json-value"}', 'json-value'],
    'quoted free text' => ["Failure for 'quoted-value'", 'quoted-value'],
    'encoded' => ['value%3Dencoded-value', 'encoded-value'],
    'multiline body' => ["Failure\nbody-value", 'body-value'],
    'trace arguments' => ["#0 /private/user/File.php(10): App\\Worker->run('argument-value')", 'argument-value'],
]);

it('groups legacy traces forward and returns newest events first with JSON details sanitized', function () {
    $directory = diagnosticDirectory($this);
    file_put_contents($directory.'/laravel.log',
        "[2026-11-17 11:00:00] local.ERROR: Worker failed\n"
        ."[stacktrace]\n#0 /private/fixture/First.php(10): App\\Worker->run('trace-secret')\n"
        ."#1 /private/fixture/Second.php(20): App\\Runner::start('second-secret')\n"
        .diagnosticJson('Service recovered', [
            'context' => ['request_id' => '981987b6-a181-4c9a-b5d3-291d64ddc8e1', 'password' => 'old-secret', 'status' => 200],
            'extra' => ['email' => 'fixture@example.test'],
        ]),
    );

    $result = (new DiagnosticLogReader)->read();

    expect($result)->toMatchArray(['state' => 'ready', 'truncated' => false, 'source' => 'laravel.log']);
    expect($result['events'])->toHaveCount(2);
    expect($result['events'][0])->toMatchArray([
        'message' => 'Service recovered', 'level' => 'error',
        'request_id' => '981987b6-a181-4c9a-b5d3-291d64ddc8e1',
        'time' => '2026-11-17T12:00:00+00:00',
    ]);
    expect($result['events'][1]['message'])->toBe('Worker failed');
    expect($result['events'][1]['details'])->toContain(
        "#0 First.php(10): App\\Worker->run()\n#1 Second.php(20): App\\Runner::start()",
    );
    expect(json_encode($result))->not->toContain('trace-secret', 'second-secret', 'old-secret', 'fixture@example.test', '/private/fixture');
    expect($result['scope'])->toContain('bounded window', 'Not complete history');
});

it('shows malformed lines without exposing their contents or trusting JSON metadata', function () {
    $directory = diagnosticDirectory($this);
    file_put_contents($directory.'/laravel.log',
        "broken-private-value\n{\"message\":\"incomplete-json-value\n"
        .diagnosticJson('Peer 192.0.2.4 failed', [
            'datetime' => 'fixture@example.test', 'level_name' => 'level-secret',
            'request_id' => 'top-level-secret',
            'context' => ['request_id' => 'request-secret', 'extra_key' => 'unexpected-secret'],
        ]),
    );

    $result = (new DiagnosticLogReader)->read();

    expect(array_column($result['events'], 'message'))->toBe([
        '[redacted sensitive diagnostic text]', '[unparsed log entry]', '[unparsed log entry]',
    ]);
    expect($result['events'][0])->toMatchArray(['time' => '', 'level' => 'unknown', 'request_id' => '']);
    expect(json_encode($result))->not->toContain(
        'broken-private-value', 'incomplete-json-value', 'fixture@example.test', 'level-secret',
        'top-level-secret', 'request-secret', 'unexpected-secret', '192.0.2.4',
    );
});

it('selects the newest supported local source and does not scan historical siblings', function (string $suffix) {
    $directory = diagnosticDirectory($this);
    $this->travelTo(new DateTimeImmutable('2026-11-17T12:00:00+00:00'));
    file_put_contents($directory.'/laravel.log', diagnosticJson('Base event'));
    file_put_contents($directory.'/laravel-2026-11.log', diagnosticJson('Monthly event'));
    file_put_contents($directory.'/laravel-2026-11-17.log', diagnosticJson('Daily event'));
    file_put_contents($directory.'/laravel-2026-11-16.log', diagnosticJson('Unsupported historical event'));
    touch($directory.'/laravel.log', 1000);
    touch($directory.'/laravel-2026-11.log', 1000);
    touch($directory.'/laravel-2026-11-17.log', 1000);
    touch($directory.'/laravel'.$suffix.'.log', 2000);
    touch($directory.'/laravel-2026-11-16.log', 3000);

    $result = (new DiagnosticLogReader)->read();

    expect($result['source'])->toBe('laravel'.$suffix.'.log');
    expect($result['events'])->toHaveCount(1);
    expect($result['events'][0]['message'])->toBe(match ($suffix) {
        '' => 'Base event', '-2026-11' => 'Monthly event', '-2026-11-17' => 'Daily event',
    });
})->with(['base' => '', 'monthly' => '-2026-11', 'daily' => '-2026-11-17']);

it('distinguishes missing empty and unreadable sources without reporting an older file as current', function () {
    $directory = diagnosticDirectory($this);
    $reader = new DiagnosticLogReader;
    expect($reader->read())->toMatchArray(['state' => 'missing', 'events' => [], 'truncated' => false]);

    file_put_contents($directory.'/laravel.log', '');
    expect($reader->read())->toMatchArray(['state' => 'ready', 'events' => [], 'truncated' => false]);

    unlink($directory.'/laravel.log');
    mkdir($directory.'/laravel.log');
    expect($reader->read())->toMatchArray(['state' => 'unreadable', 'events' => []]);
});

it('rejects nonlocal configured sources without opening a stream', function (string $path) {
    config(['diagnostics.path' => $path]);

    expect((new DiagnosticLogReader)->read())->toMatchArray(['state' => 'unreadable', 'events' => []]);
})->with(['php://memory', 'https://example.test/logs', '//host/share/laravel.log', "invalid\0.log"]);

it('caps events at the most recent two hundred', function () {
    $directory = diagnosticDirectory($this);
    $contents = '';

    for ($index = 1; $index <= 205; $index++) {
        $contents .= diagnosticJson('Event '.$index);
    }

    file_put_contents($directory.'/laravel.log', $contents);

    $result = (new DiagnosticLogReader)->read();

    expect($result['events'])->toHaveCount(200);
    expect($result['events'][0]['message'])->toBe('Event 205');
    expect($result['events'][199]['message'])->toBe('Event 6');
    expect($result['truncated'])->toBeTrue();
});

it('omits oversized events and does not emit a secret cut at an event boundary', function () {
    $directory = diagnosticDirectory($this);
    file_put_contents($directory.'/laravel.log',
        diagnosticJson('Start '.str_repeat('padding ', 1200).'password=oversized-secret')
        ."[2026-11-17 12:00:00] local.ERROR: Legacy large\n".str_repeat("private-padding\n", 800)
        .diagnosticJson('Newest complete event'),
    );

    $result = (new DiagnosticLogReader)->read();

    expect($result['truncated'])->toBeTrue();
    expect(array_column($result['events'], 'message'))->toBe([
        'Newest complete event', '[oversized event omitted]', '[oversized event omitted]',
    ]);
    expect(json_encode($result))->not->toContain('oversized-secret', 'private-padding');

    foreach ($result['events'] as $event) {
        expect(strlen(json_encode($event)))->toBeLessThanOrEqual(8192);
    }
});

it('scans at most the last 256 KiB and drops leading orphan continuations and incomplete tails', function () {
    $directory = diagnosticDirectory($this);
    file_put_contents($directory.'/laravel.log',
        diagnosticJson('Outside bounded scan')
        ."[2026-11-17 12:00:00] local.ERROR: Body start\n"
        .str_repeat("orphan-secret\n", 23000)
        .diagnosticJson('Inside bounded scan')
        .'{"message":"password=unfinished-secret',
    );

    $result = (new DiagnosticLogReader)->read();

    expect($result['events'])->toHaveCount(1);
    expect($result['events'][0]['message'])->toBe('Inside bounded scan');
    expect($result['truncated'])->toBeTrue();
    expect(json_encode($result))->not->toContain('Outside bounded scan', 'orphan-secret', 'unfinished-secret');
});

it('drops a legacy group whose last continuation is still being written', function () {
    $directory = diagnosticDirectory($this);
    file_put_contents($directory.'/laravel.log', diagnosticJson('Completed event')
        ."[2026-11-17 12:00:00] local.ERROR: In progress\n#0 File.php(10): App\\Worker->run('partial-secret",
    );

    $result = (new DiagnosticLogReader)->read();

    expect(array_column($result['events'], 'message'))->toBe(['Completed event']);
    expect($result['truncated'])->toBeTrue();
});

it('rechecks source metadata after rotation and removal without retaining old events', function () {
    $directory = diagnosticDirectory($this);
    $this->travelTo(new DateTimeImmutable('2026-11-17T12:00:00+00:00'));
    $reader = new DiagnosticLogReader;
    file_put_contents($directory.'/laravel.log', diagnosticJson('Before rotation'));
    expect($reader->read()['events'][0]['message'])->toBe('Before rotation');

    rename($directory.'/laravel.log', $directory.'/laravel-2026-11-16.log');
    file_put_contents($directory.'/laravel-2026-11-17.log', diagnosticJson('After rotation'));
    expect($reader->read())->toMatchArray(['source' => 'laravel-2026-11-17.log', 'state' => 'ready']);
    expect($reader->read()['events'][0]['message'])->toBe('After rotation');

    unlink($directory.'/laravel-2026-11-17.log');
    expect($reader->read())->toMatchArray(['state' => 'missing', 'events' => []]);
});

it('sanitizes historical JSON exceptions and preserves their class and argument-free frames', function () {
    $directory = diagnosticDirectory($this);
    file_put_contents($directory.'/laravel.log', diagnosticJson('Stored failure', [
        'level_name' => null,
        'level' => 400,
        'context' => ['exception' => [
            'class' => RuntimeException::class,
            'message' => 'postgres://fixture:historical-secret@host/db',
            'trace' => ["#0 /private/Worker.php(12): App\\Worker->run('historical-argument')"],
            'bindings' => ['historical-binding'],
        ]],
    ]));

    $event = (new DiagnosticLogReader)->read()['events'][0];

    expect(array_keys($event))->toBe(['time', 'level', 'message', 'details', 'request_id']);
    expect($event['level'])->toBe('error');
    expect($event['details'])->toContain('RuntimeException', 'Worker.php(12)');
    expect(json_encode($event))->not->toContain('historical-secret', 'historical-argument', 'historical-binding');
});

it('retains a legacy exception class while omitting its payload and trace arguments', function () {
    $directory = diagnosticDirectory($this);
    file_put_contents($directory.'/laravel.log',
        '[2026-11-17 12:00:00] local.ERROR: Worker failed {"exception":"[object] (RuntimeException(code: 0): password=legacy-secret at /private/Worker.php:12)'."\n"
        ."[stacktrace]\n#0 /private/Worker.php(12): App\\Worker->run('legacy-argument')\n",
    );

    $event = (new DiagnosticLogReader)->read()['events'][0];

    expect($event['message'])->toBe('Worker failed [context omitted]');
    expect($event['details'])->toContain('RuntimeException', '#0 Worker.php(12): App\\Worker->run()');
    expect(json_encode($event))->not->toContain('legacy-secret', 'legacy-argument', '/private');
});

it('reports the newest unreadable sibling instead of silently displaying stale base data', function () {
    $directory = diagnosticDirectory($this);
    $this->travelTo(new DateTimeImmutable('2026-11-17T12:00:00+00:00'));
    file_put_contents($directory.'/laravel.log', diagnosticJson('Stale base event'));
    touch($directory.'/laravel.log', 1000);
    mkdir($directory.'/laravel-2026-11-17.log');

    expect((new DiagnosticLogReader)->read())->toMatchArray([
        'state' => 'unreadable', 'source' => 'laravel-2026-11-17.log', 'events' => [],
    ]);
});

it('uses the default storage log path only when no diagnostic path is configured', function () {
    $directory = diagnosticDirectory($this);
    $this->app->useStoragePath($directory);
    config(['diagnostics' => []]);
    mkdir($directory.'/logs');
    file_put_contents($directory.'/logs/laravel.log', diagnosticJson('Default path event'));

    $result = (new DiagnosticLogReader)->read();

    expect($result['source'])->toBe('laravel.log');
    expect($result['events'][0]['message'])->toBe('Default path event');
});

it('preserves commit labels and exposes a generated event ID for sink correlation', function () {
    $directory = diagnosticDirectory($this);
    config(['diagnostics.release' => str_repeat('abcdef12', 5)]);
    $logger = (new LogManager($this->app))->channel('single');

    $logger->error('Synthetic correlation event');
    $logger->getLogger()->close();

    $stored = json_decode(file_get_contents($directory.'/laravel.log'), true, 32, JSON_THROW_ON_ERROR);
    $event = (new DiagnosticLogReader)->read()['events'][0];
    expect($stored['extra']['release'])->toBe(str_repeat('abcdef12', 5));
    expect($event['request_id'])->toBeUuid()->toBe($stored['extra']['event_id']);
});

it('does not invent a timezone for historical timestamps without an offset', function () {
    $directory = diagnosticDirectory($this);
    file_put_contents($directory.'/laravel.log', "[2026-11-17 12:00:00] local.ERROR: Historical event\n");

    expect((new DiagnosticLogReader)->read()['events'][0]['time'])->toBe('2026-11-17 12:00:00');
});

it('routes nginx access output to stdout without request targets headers or peer identity', function () {
    $template = file_get_contents(base_path('docker/nginx/default.conf.template'));
    $format = strstr($template, 'server {', true);

    expect($template)->toContain('access_log /dev/stdout diagnostics;', 'error_log /dev/stderr warn;');
    expect($format)->not->toContain('$request"', '$request_uri', '$uri', '$args', '$query_string', '$remote_addr', '$remote_user', '$http_');
});
