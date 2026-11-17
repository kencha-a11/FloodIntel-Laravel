<?php

namespace App\Services;

use App\Logging\SanitizeDiagnosticRecord;
use DateTimeImmutable;
use Throwable;

final class DiagnosticLogReader
{
    private const MAX_BYTES = 262144;

    private const MAX_EVENT_BYTES = 8192;

    private const MAX_EVENTS = 200;

    private const SCOPE = 'Latest supported local file only; bounded window of at most 200 events, 256 KiB scanned and 8 KiB per event. Not complete history; rotated files are not merged.';

    private const LEGACY_HEADER = '/^\[([0-9T:.+ Z-]{19,40})\]\s+[^\s.]+\.([a-z]+):\s?(.*)$/i';

    /**
     * @return array{events: list<array{time: string, level: string, message: string, details: string, request_id: string}>, state: 'ready'|'missing'|'unreadable', truncated: bool, source: string, scope: string}
     */
    public function read(): array
    {
        $path = config('diagnostics.path', storage_path('logs/laravel.log'));

        if (! is_string($path) || str_contains($path, "\0") || str_contains($path, '://')
            || str_starts_with(str_replace('\\', '/', $path), '//')
            || preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $path) !== 1) {
            return $this->result('unreadable', 'diagnostics.log');
        }

        $source = basename(str_replace('\\', '/', $path));

        // Retry metadata/open once if rotation removes a candidate. Never retry
        // a scan: the read budget is total, not a per-attempt limit.
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $candidates = $this->candidates($path);

            if ($candidates === []) {
                return $this->result('missing', $source);
            }

            $candidate = $candidates[0]['path'];
            $source = basename($candidate);
            clearstatcache(true, $candidate);

            if (is_link($candidate) || ! is_file($candidate) || ! is_readable($candidate)) {
                if (! file_exists($candidate) && ! is_link($candidate)) {
                    continue;
                }

                return $this->result('unreadable', $source);
            }

            $before = @stat($candidate);
            $stream = @fopen($candidate, 'rb');

            if ($stream === false) {
                if (! file_exists($candidate)) {
                    continue;
                }

                return $this->result('unreadable', $source);
            }

            try {
                $snapshot = fstat($stream);

                if ($before === false || $snapshot === false || ($snapshot['mode'] & 0170000) !== 0100000
                    || $before['ino'] !== $snapshot['ino'] || $before['dev'] !== $snapshot['dev']
                    || is_link($candidate)) {
                    return $this->result('unreadable', $source);
                }

                $size = $snapshot['size'];
                $offset = max(0, $size - self::MAX_BYTES);

                if (fseek($stream, $offset) !== 0) {
                    return $this->result('unreadable', $source);
                }

                $bytes = $size === 0 ? '' : @stream_get_contents($stream, min($size, self::MAX_BYTES));
                $after = fstat($stream);

                if ($bytes === false || $after === false) {
                    return $this->result('unreadable', $source);
                }

                if ($after['size'] < $size || strlen($bytes) !== min($size, self::MAX_BYTES)) {
                    return $this->result('ready', $source, [], true);
                }

                [$events, $truncated] = $this->parse($bytes, $offset > 0);

                return $this->result('ready', $source, $events, $truncated || $after['size'] > $size);
            } finally {
                fclose($stream);
            }
        }

        return $this->result('missing', $source);
    }

    /** @return list<array{path: string, mtime: int}> */
    private function candidates(string $path): array
    {
        $path = str_replace('\\', '/', $path);
        $info = pathinfo($path);
        $stem = $info['dirname'].'/'.$info['filename'];
        $extension = isset($info['extension']) ? '.'.$info['extension'] : '';
        $paths = [$stem.'-'.now()->format('Y-m-d').$extension, $stem.'-'.now()->format('Y-m').$extension, $path];
        $candidates = [];

        foreach ($paths as $candidate) {
            clearstatcache(true, $candidate);
            $stat = @lstat($candidate);

            if ($stat !== false) {
                $candidates[] = ['path' => $candidate, 'mtime' => $stat['mtime']];
            }
        }

        usort($candidates, fn (array $left, array $right): int => $right['mtime'] <=> $left['mtime']);

        return $candidates;
    }

    /** @return array{0: list<array{time: string, level: string, message: string, details: string, request_id: string}>, 1: bool} */
    private function parse(string $bytes, bool $cut): array
    {
        $truncated = $cut;
        $seekingBoundary = $cut;

        if ($cut) {
            $newline = strpos($bytes, "\n");
            $bytes = $newline === false ? '' : substr($bytes, $newline + 1);
        }

        $incomplete = $bytes !== '' && ! str_ends_with($bytes, "\n");

        if ($incomplete) {
            $truncated = true;
            $newline = strrpos($bytes, "\n");
            $bytes = $newline === false ? '' : substr($bytes, 0, $newline + 1);
        }

        $events = [];
        $block = null;

        foreach (explode("\n", $bytes) as $line) {
            $line = rtrim($line, "\r");
            $legacy = preg_match(self::LEGACY_HEADER, $line) === 1;
            $json = str_starts_with(ltrim($line), '{');

            if ($seekingBoundary && ! $legacy && ! $json) {
                continue;
            }

            $seekingBoundary = false;

            if ($legacy || $json) {
                if ($block !== null) {
                    $this->append($events, $this->event($block, $truncated), $truncated);
                    $block = null;
                }

                if ($legacy) {
                    $block = $line;
                } else {
                    $this->append($events, $this->event($line, $truncated), $truncated);
                }
            } elseif ($block !== null) {
                // Retain one over-limit sentinel byte, never an unbounded event.
                $block = substr($block."\n".$line, 0, self::MAX_EVENT_BYTES + 1);
            } elseif (trim($line) !== '') {
                $this->append($events, $this->unparsed(), $truncated);
            }
        }

        if ($block !== null && ! $incomplete) {
            $this->append($events, $this->event($block, $truncated), $truncated);
        }

        return [array_reverse($events), $truncated];
    }

    /** @return array{time: string, level: string, message: string, details: string, request_id: string} */
    private function event(string $block, bool &$truncated): array
    {
        if (strlen($block) > self::MAX_EVENT_BYTES) {
            $truncated = true;

            return $this->unparsed('[oversized event omitted]');
        }

        $lines = explode("\n", $block);

        if (preg_match(self::LEGACY_HEADER, $lines[0], $header) === 1) {
            $context = [];
            $message = $header[3];
            $contextStart = strpos($message, ' {');

            if ($contextStart !== false) {
                $decoded = json_decode(substr($message, $contextStart + 1), true, 32);
                $context = is_array($decoded) ? SanitizeDiagnosticRecord::context($decoded) : [];
                $message = substr($message, 0, $contextStart).' [context omitted]';
            }

            $details = count($lines) > 1 ? SanitizeDiagnosticRecord::text("[stacktrace]\n".implode("\n", array_slice($lines, 1))) : '';

            return $this->bounded([
                'time' => $this->time($header[1]),
                'level' => $this->level($header[2]),
                'message' => SanitizeDiagnosticRecord::text($message),
                'details' => $details,
                'request_id' => $context['request_id'] ?? '',
            ], $truncated);
        }

        $record = json_decode($block, true, 32);

        if (! is_array($record) || ! is_string($record['message'] ?? null)) {
            return $this->unparsed();
        }

        $context = SanitizeDiagnosticRecord::context(is_array($record['context'] ?? null) ? $record['context'] : []);
        $extra = SanitizeDiagnosticRecord::context(is_array($record['extra'] ?? null) ? $record['extra'] : []);
        $details = array_filter(['context' => $context, 'extra' => $extra]);

        return $this->bounded([
            'time' => $this->time($record['datetime'] ?? $record['time'] ?? ''),
            'level' => $this->level($record['level_name'] ?? $record['level'] ?? ''),
            'message' => SanitizeDiagnosticRecord::text($record['message']),
            'details' => $details === [] ? '' : (string) json_encode($details, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE),
            'request_id' => $context['request_id'] ?? $extra['request_id'] ?? '',
        ], $truncated);
    }

    /** @param array{time: string, level: string, message: string, details: string, request_id: string} $event
     *  @return array{time: string, level: string, message: string, details: string, request_id: string}
     */
    private function bounded(array $event, bool &$truncated): array
    {
        if (strlen((string) json_encode($event, JSON_INVALID_UTF8_SUBSTITUTE)) > self::MAX_EVENT_BYTES) {
            $truncated = true;
            $event['message'] = '[oversized event omitted]';
            $event['details'] = '';
        }

        return $event;
    }

    /** @param list<array<string, string>> $events
     *  @param array<string, string> $event
     */
    private function append(array &$events, array $event, bool &$truncated): void
    {
        $events[] = $event;

        if (count($events) > self::MAX_EVENTS) {
            array_shift($events);
            $truncated = true;
        }
    }

    private function time(mixed $value): string
    {
        if (! is_string($value) || preg_match('/\A[0-9T:.+ Z-]{19,40}\z/', $value) !== 1) {
            return '';
        }

        try {
            return (new DateTimeImmutable($value))->format('Y-m-d\TH:i:sP');
        } catch (Throwable) {
            return '';
        }
    }

    private function level(mixed $value): string
    {
        $level = is_string($value) ? strtolower($value) : '';

        return in_array($level, ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'], true) ? $level : 'unknown';
    }

    /** @return array{time: string, level: string, message: string, details: string, request_id: string} */
    private function unparsed(string $message = '[unparsed log entry]'): array
    {
        return ['time' => '', 'level' => 'unknown', 'message' => $message, 'details' => '[unparsed content omitted]', 'request_id' => ''];
    }

    /** @param list<array<string, string>> $events
     *  @return array{events: list<array<string, string>>, state: string, truncated: bool, source: string, scope: string}
     */
    private function result(string $state, string $source, array $events = [], bool $truncated = false): array
    {
        return [
            'events' => $events,
            'state' => $state,
            'truncated' => $truncated,
            'source' => SanitizeDiagnosticRecord::text($source),
            'scope' => self::SCOPE,
        ];
    }
}
