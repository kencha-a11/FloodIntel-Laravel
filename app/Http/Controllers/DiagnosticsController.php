<?php

namespace App\Http\Controllers;

use App\Services\DiagnosticLogReader;
use DateTimeImmutable;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class DiagnosticsController extends Controller
{
    public function logs(Request $request): Response
    {
        return $this->respond($request);
    }

    public function clearLogs(Request $request): Response
    {
        return $this->respond($request, clearing: true);
    }

    private function respond(Request $request, bool $clearing = false): Response
    {
        $nonce = base64_encode(random_bytes(24));

        try {
            $response = $this->handle($request, $nonce, $clearing);
        } catch (Throwable) {
            $response = response()->json(['message' => 'Diagnostics are temporarily unavailable.'], 503);
        }

        $response->headers->add([
            'Cache-Control' => 'no-store, private',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Content-Security-Policy' => "default-src 'none'; script-src 'nonce-{$nonce}'; style-src 'nonce-{$nonce}'; connect-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
        ]);

        return $response;
    }

    private function handle(Request $request, string $nonce, bool $clearing): Response
    {
        if (! app()->environment(['local', 'testing']) && ! $request->secure()) {
            return response()->json(['message' => 'Diagnostics require HTTPS.'], 403);
        }

        $cache = Cache::build([
            'driver' => 'file',
            'path' => config('diagnostics.cache_path'),
            'lock_path' => config('diagnostics.cache_path'),
        ]);
        $key = 'diagnostics:'.hash('sha256', (string) $request->ip());
        $retryAfter = $cache->lock($key.':lock', 5)->block(1, function () use ($cache, $key): int {
            $limiter = new RateLimiter($cache);
            if ($limiter->tooManyAttempts($key, 30)) {
                return max(1, $limiter->availableIn($key));
            }
            $limiter->hit($key, 60);

            return 0;
        });

        if ($retryAfter > 0) {
            return response()->json(['message' => 'Too many diagnostic requests. Try again shortly.'], 429)
                ->header('Retry-After', (string) $retryAfter);
        }

        if (! $clearing && ! $request->expectsJson() && ! $request->hasHeader('X-Diagnostics-Key')) {
            return response()->view('diagnostics.logs', ['nonce' => $nonce]);
        }

        $expected = (string) config('diagnostics.key');
        $expiry = (string) config('diagnostics.expires_at');
        $expiresAt = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $expiry);
        if (strlen($expected) < 32 || ! $expiresAt || $expiresAt->format('Y-m-d\TH:i:sP') !== $expiry) {
            return response()->json(['message' => 'Diagnostics access is not configured.'], 503);
        }

        if ($expiresAt->getTimestamp() <= now()->getTimestamp()) {
            return response()->json(['message' => 'Diagnostics access has expired.'], 403);
        }

        if (! hash_equals($expected, (string) $request->header('X-Diagnostics-Key', ''))) {
            return response()->json(['message' => 'Diagnostics access denied.'], 403);
        }

        if ($clearing) {
            return response()->json(['message' => 'Log clearing is disabled to preserve incident evidence.'], 403);
        }

        $result = app(DiagnosticLogReader::class)->read();

        return response()->json($result + [
            'environment' => app()->environment(),
            'release' => preg_replace('/[^a-zA-Z0-9._-]/', '', substr((string) config('diagnostics.release'), 0, 80)),
            'instance' => preg_replace('/[^a-zA-Z0-9._-]/', '', substr((string) config('diagnostics.instance'), 0, 80)),
            'expires_at' => $expiry,
            'fetched_at' => now()->toIso8601String(),
        ], $result['state'] === 'unreadable' ? 503 : 200);
    }
}
