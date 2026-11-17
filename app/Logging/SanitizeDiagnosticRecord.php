<?php

namespace App\Logging;

use Illuminate\Support\Str;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Throwable;

final class SanitizeDiagnosticRecord implements ProcessorInterface
{
    private const REDACTED = '[redacted sensitive diagnostic text]';

    public function __invoke(LogRecord $record): LogRecord
    {
        $extra = self::context($record->extra);
        $extra['event_id'] = self::uuid($extra['event_id'] ?? null) ?: (string) Str::uuid();

        foreach (['release', 'instance'] as $key) {
            $value = self::label(config('diagnostics.'.$key));

            if ($value !== '') {
                $extra[$key] = $value;
            }
        }

        return $record->with(
            message: self::text($record->message),
            context: self::context($record->context),
            extra: $extra,
            channel: 'diagnostics',
        );
    }

    // Keep first-line prose and argument-free frames; omit unstructured payloads.
    public static function text(string $text): string
    {
        if (strlen($text) > 8192 || ! mb_check_encoding($text, 'UTF-8')) {
            return '[oversized or invalid diagnostic text omitted]';
        }

        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text) ?? '';
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $result = [];

        foreach ($lines as $index => $line) {
            $frame = self::frame($line);

            if ($frame !== null) {
                $result[] = $frame;

                continue;
            }

            if (in_array(trim($line), ['[stacktrace]', '[unstructured continuation omitted]', self::REDACTED], true)) {
                $result[] = trim($line);

                continue;
            }

            if ($index > 0) {
                $result[] = '[unstructured continuation omitted]';

                continue;
            }

            $sensitive = '/(?:[a-z][a-z0-9+.-]*:\/\/|\b(?:SQLSTATE|select\s|insert\s|update\s|delete\s|bindings?|password|passwd|pwd|secret|credential|authorization|authentication|bearer|basic|cookie|token|api[_-]?key|private[_-]?key|request[_ -]?(?:body|payload)|payload|body|dsn|connection|email|ip[_ -]?address)\b|[?&][^\s=]+\s*=|%[0-9a-f]{2}|\\\\u[0-9a-f]{4}|[{}]|\[[^\]]*[:=]|[\w.+-]+@[\w.-]+|\b(?:\d{1,3}\.){3}\d{1,3}\b|(?:[a-f0-9]{0,4}:){2,}[a-f0-9:.]*|\beyJ[a-z0-9_-]+\.|\b[a-z0-9_+\/-]{32,}={0,2}\b|\b[\w.-]+\s*[:=]\s*\S+)/i';

            if (preg_match($sensitive, $line) !== 0) {
                $result[] = self::REDACTED;

                continue;
            }

            $line = preg_replace('/([\'"]).*?\1/', '[redacted quoted value]', $line) ?? self::REDACTED;
            $line = preg_replace('~(?:[A-Za-z]:[\\\\/]|/)(?:[^\s/\\\\]+[\\\\/])+[^\s]*~', '[path omitted]', $line) ?? self::REDACTED;
            $result[] = $line;
        }

        return implode("\n", $result);
    }

    /** @param array<mixed> $context
     * @return array<string, mixed>
     */
    public static function context(array $context): array
    {
        $safe = [];

        foreach (['request_id', 'event_id'] as $key) {
            if (($value = self::uuid($context[$key] ?? null)) !== '') {
                $safe[$key] = $value;
            }
        }

        foreach (['release', 'instance'] as $key) {
            if (($value = self::label($context[$key] ?? null)) !== '') {
                $safe[$key] = $value;
            }
        }

        foreach (['status' => 599, 'attempt' => 1000, 'duration_ms' => 3600000] as $key => $maximum) {
            $value = $context[$key] ?? null;

            if ((is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 0 && $value <= $maximum) {
                $safe[$key] = $value;
            }
        }

        if (in_array($context['method'] ?? null, ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], true)) {
            $safe['method'] = $context['method'];
        }

        if (isset($context['exception'])) {
            $exception = self::exception($context['exception']);

            if ($exception !== []) {
                $safe['exception'] = $exception;
            }
        }

        return $safe;
    }

    public static function uuid(mixed $value): string
    {
        return is_string($value) && Str::isUuid($value) ? strtolower($value) : '';
    }

    private static function label(mixed $value): string
    {
        if (! is_string($value) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,63}\z/', $value) !== 1) {
            return '';
        }

        if (preg_match('/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/i', $value) === 1 || Str::isUuid($value)) {
            return $value;
        }

        return self::text($value) === $value ? $value : '';
    }

    /** @return array{class: string, message: string, trace: list<string>}|array{} */
    private static function exception(mixed $exception): array
    {
        if ($exception instanceof Throwable) {
            $trace = ['#0 '.$exception->getFile().'('.$exception->getLine().'): {main}'];

            foreach (array_slice($exception->getTrace(), 0, 19) as $index => $frame) {
                $trace[] = '#'.($index + 1).' '.($frame['file'] ?? '[internal function]').'('.($frame['line'] ?? 0).'): '
                    .($frame['class'] ?? '').($frame['type'] ?? '').($frame['function'] ?? '{main}').'()';
            }

            $exception = ['class' => $exception::class, 'message' => $exception->getMessage(), 'trace' => $trace];
        }

        if (! is_array($exception) || ! is_string($exception['class'] ?? null)
            || preg_match('/\A[A-Za-z_\\\\][A-Za-z0-9_\\\\]{0,199}\z/', $exception['class']) !== 1) {
            return [];
        }

        $trace = [];

        foreach (array_slice(is_array($exception['trace'] ?? null) ? $exception['trace'] : [], 0, 20) as $line) {
            if (is_string($line) && strlen($line) <= 8192 && ($frame = self::frame($line)) !== null) {
                $trace[] = $frame;
            }
        }

        return [
            'class' => $exception['class'],
            'message' => is_string($exception['message'] ?? null) ? self::text($exception['message']) : '[exception message omitted]',
            'trace' => $trace,
        ];
    }

    private static function frame(string $line): ?string
    {
        if (preg_match('/^\s*#(\d{1,4})\s+(.*)$/', $line, $parts) !== 1) {
            return null;
        }

        $prefix = '#'.$parts[1].' ';
        $location = '';
        $call = $parts[2];

        if (preg_match('/^(.*?)\((\d+)\):\s*(.*)$/', $call, $locationParts) === 1) {
            $file = basename(str_replace('\\', '/', $locationParts[1]));
            $location = preg_match('/\A[A-Za-z0-9_.-]+\.php\z/', $file) === 1 && self::text($file) === $file
                ? $file.'('.$locationParts[2].'): ' : '[internal function]: ';
            $call = $locationParts[3];
        } elseif (str_starts_with($call, '[internal function]: ')) {
            $location = '[internal function]: ';
            $call = substr($call, strlen($location));
        }

        $call = preg_replace('/\(.*$/', '()', $call) ?? '';

        if (preg_match('/\A(?:[A-Za-z_\\\\][A-Za-z0-9_\\\\]*(?:(?:->|::)[A-Za-z_][A-Za-z0-9_]*)?\(\)|\{(?:main|closure)\}(?:\(\))?)\z/', $call) !== 1) {
            return $prefix.'[frame omitted]';
        }

        return $prefix.$location.$call;
    }
}
