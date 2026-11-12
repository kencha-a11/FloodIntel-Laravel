<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class DiagnosticsController extends Controller
{
    /**
     * Maximum number of log lines to return in a single page view.
     */
    const MAX_LINES = 200;

    /**
     * Show recent application logs as an HTML page.
     *
     * Access is gated by a secret query key (`?key=...`) so the endpoint is
     * not publicly guessable. No session or Sanctum token is required.
     */
    public function logs(Request $request)
    {
        try {
            return $this->handleLogs($request);
        } catch (\Throwable $e) {
            Log::error('Diagnostics logs failed: '.$e->getMessage(), ['exception' => $e]);

            return response('Diagnostics error: '.$e->getMessage(), 500);
        }
    }

    private function handleLogs(Request $request)
    {
        $expectedKey = (string) env('DIAGNOSTICS_KEY', '');

        if (blank($expectedKey)) {
            return response('Diagnostics are not configured on this server.', 503);
        }

        $providedKey = $request->query('key', '');

        if (! Str::equals($providedKey, $expectedKey)) {
            return response('Forbidden', 403);
        }

        $logPath = storage_path('logs/laravel.log');

        if (! file_exists($logPath)) {
            return view('diagnostics.logs', [
                'lines' => [],
                'message' => 'No log file found at storage/logs/laravel.log. File logging may not be enabled yet.',
            ]);
        }

        $rawLines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        // Show the most recent lines first, capped to MAX_LINES.
        $lines = array_slice($rawLines, -self::MAX_LINES);
        $lines = array_reverse($lines);

        return view('diagnostics.logs', [
            'lines' => $lines,
            'total' => count($rawLines),
            'shown' => count($lines),
        ]);
    }

    /**
     * Deliberately throw an exception so you can verify that 500 errors are
     * being written to the log file.
     */
    public function test500(Request $request)
    {
        try {
            return $this->handleTest500($request);
        } catch (\Throwable $e) {
            Log::error('Diagnostics test-500 failed: '.$e->getMessage(), ['exception' => $e]);

            return response('Diagnostics error: '.$e->getMessage(), 500);
        }
    }

    /**
     * TEMPORARY: Show logs without a secret key. Remove this after debugging.
     */
    public function logsNoKey(Request $request)
    {
        try {
            $logPath = storage_path('logs/laravel.log');

            if (! file_exists($logPath)) {
                return response('No log file found at storage/logs/laravel.log.', 404);
            }

            $rawLines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

            $lines = array_slice($rawLines, -self::MAX_LINES);
            $lines = array_reverse($lines);

            return view('diagnostics.logs', [
                'lines' => $lines,
                'total' => count($rawLines),
                'shown' => count($lines),
            ]);
        } catch (\Throwable $e) {
            Log::error('Diagnostics no-key failed: '.$e->getMessage(), ['exception' => $e]);

            return response('Diagnostics error: '.$e->getMessage(), 500);
        }
    }

    /**
     * TEMPORARY: Clear the application log file. Remove this after debugging.
     */
    public function clearLogs(Request $request)
    {
        try {
            $logPath = storage_path('logs/laravel.log');

            if (! file_exists($logPath)) {
                return response('No log file found.', 404);
            }

            // Truncate the log file to zero bytes.
            file_put_contents($logPath, '');

            Log::warning('Diagnostics clear-logs triggered manually.');

            return response('', 204);
        } catch (\Throwable $e) {
            Log::error('Diagnostics clear-logs failed: '.$e->getMessage(), ['exception' => $e]);

            return response('Diagnostics error: '.$e->getMessage(), 500);
        }
    }

    private function handleTest500(Request $request)
    {
        $expectedKey = (string) env('DIAGNOSTICS_KEY', '');

        if (blank($expectedKey)) {
            return response('Diagnostics are not configured on this server.', 503);
        }

        $providedKey = $request->query('key', '');

        if (! Str::equals($providedKey, $expectedKey)) {
            return response('Forbidden', 403);
        }

        Log::error('Diagnostics test-500 triggered manually.');

        throw new \RuntimeException('This is a deliberate 500 test from DiagnosticsController@test500.');
    }
}
