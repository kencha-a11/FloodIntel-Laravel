<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ServerController;

// -------------------------------------------------------------
// Core System & API Group
// -------------------------------------------------------------
Route::prefix('server')->group(function () {
    require __DIR__ . '/server.php';
});

// Main landing (API welcome)
Route::get('/', function () {
    return response()->json([
        'message' => 'Welcome to the FloodIntel Server!',
        'documentation' => url('/docs')
    ]);
});

// -------------------------------------------------------------
// Log Viewer (Controller-based for cache safety)
// -------------------------------------------------------------
Route::get('/logs', [ServerController::class, 'showLogs']);
Route::post('/logs/clear', [ServerController::class, 'clearLogs']);

Route::get('/logs/test', function () {
    Log::info('Test log entry at ' . now());
    return response()->json(['message' => 'Test log entry created. ' . now()]);
});

Route::get('/debug-mail', function () {
    return [
        'MAIL_MAILER' => env('MAIL_MAILER'),
        'MAIL_HOST' => env('MAIL_HOST'),
        'MAIL_PORT' => env('MAIL_PORT'),
        'MAIL_USERNAME' => env('MAIL_USERNAME'),
        'MAIL_ENCRYPTION' => env('MAIL_ENCRYPTION'),
        'MAIL_FROM_ADDRESS' => env('MAIL_FROM_ADDRESS'),
        'MAIL_FROM_NAME' => env('MAIL_FROM_NAME'),
        'config/mail.mailer' => config('mail.mailer'),
        'config/mail.from' => config('mail.from'),
    ];
});

Route::get('/test-mail', function () {
    try {
        Mail::raw('Test from Render', function ($message) {
            $message->to('aljonkenfernandez36@gmail.com')
                ->from(env('MAIL_FROM_ADDRESS'), 'FloodIntel')
                ->subject('SMTP Test');
        });
        return 'Mail sent (no exception)';
    } catch (\Exception $e) {
        return 'Error: ' . $e->getMessage();
    }
});

Route::get('/test-connection', function () {
    $host = 'smtp-relay.brevo.com';
    $port = 2525; // test both 587 and 465

    $fp = @fsockopen($host, $port, $errno, $errstr, 5);
    if ($fp) {
        fclose($fp);
        return "Connection to $host:$port succeeded.";
    } else {
        return "Connection to $host:$port failed: $errstr ($errno)";
    }
});
