<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'supabase' => [
        'url' => env('SUPABASE_URL'),
        'anon_key' => env('SUPABASE_ANON_KEY'),
        'service_role_key' => env('SUPABASE_SERVICE_ROLE_KEY'),
        'jwt_secret' => env('SUPABASE_JWT_SECRET'),
        'realtime_token_ttl' => (int) env('SUPABASE_REALTIME_TOKEN_TTL', 600),
        'broadcast_timeout' => (int) env('SUPABASE_BROADCAST_TIMEOUT', 5),
    ],

    'geocoder' => [
        'url' => env('GEOCODER_URL', 'https://nominatim.openstreetmap.org'),
        'user_agent' => env(
            'GEOCODER_USER_AGENT',
            'FloodIntel/1.0 (capstone flood reporting; contact: admin@floodintel.local)',
        ),
        'key' => env('GEOCODER_API_KEY'),
        'timeout' => (int) env('GEOCODER_TIMEOUT', 3),
        'min_interval_ms' => (int) env('GEOCODER_MIN_INTERVAL_MS', 1000),
    ],

];
