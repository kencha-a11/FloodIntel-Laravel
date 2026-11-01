<?php

namespace App\Http\Middleware;

use App\Support\DatabaseIdentity;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Open the request's transaction with the authenticated user's identity
 * (FR-65), after auth and before route model binding substitutes a model.
 */
final class SetDatabaseIdentity
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        return DatabaseIdentity::runAs(
            $user->getKey(),
            fn (): Response => $next($request),
        );
    }
}
