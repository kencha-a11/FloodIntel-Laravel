<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php', // Ito ang main entry point para sa lahat ng API versions
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Dito mo ilalagay ang global middleware sa hinaharap
        $middleware->alias([
            'terms' => \App\Http\Middleware\EnsureTermsAccepted::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Dito ang handling ng errors
    })->create();