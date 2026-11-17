<?php

return [
    'key' => env('DIAGNOSTICS_KEY', ''),
    'expires_at' => env('DIAGNOSTICS_EXPIRES_AT', ''),
    'path' => storage_path('logs/laravel.log'),
    'cache_path' => storage_path('framework/cache/diagnostics'),
    'release' => env('RENDER_GIT_COMMIT', 'unknown'),
    'instance' => env('RENDER_INSTANCE_ID', 'local'),
];
