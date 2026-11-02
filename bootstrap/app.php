<?php

use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\RejectOversizedRequests;
use App\Http\Middleware\SetDatabaseIdentity;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The declared request-body ceiling answers first, before routing,
        // auth, or PHP's own post_max_size message can.
        $middleware->prepend(RejectOversizedRequests::class);

        // Proxy trust is applied in AppServiceProvider::boot(), not here: this
        // callback runs before the environment and config are loaded, so a
        // config read at this point is always empty. Trusting every proxy
        // would let any caller pick the address IP-keyed limits are keyed on.

        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('api/*') ? null : route('login'),
        );

        $middleware->alias([
            'role' => EnsureUserHasRole::class,
            'db.identity' => SetDatabaseIdentity::class,
        ]);

        // Identity must be set after the user is resolved and before a route
        // model binding queries a policy-protected table (FR-65).
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: SetDatabaseIdentity::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (InvalidSignatureException $exception, Request $request) {
            $frontendUrl = config('app.frontend_url');

            if (! $request->wantsJson() && filled($frontendUrl)) {
                return redirect()->away($frontendUrl.'?email=verification-failed');
            }

            return null;
        });
    })->create();
