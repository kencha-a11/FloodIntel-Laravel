<?php

use App\Mail\TestBrevoEmail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/test-email', function () {
    try {
        Mail::to('aljonkenfernandez36@gmail.com')->send(new TestBrevoEmail);

        return response()->json([
            'status' => 'success',
            'message' => 'Test email sent successfully! Check your inbox at aljonkenfernandez36@gmail.com',
        ]);
    } catch (Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => 'Failed to send test email',
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ], 500);
    }
});
