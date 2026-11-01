<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuse a body above the declared ceiling before routing or validation.
 *
 * PHP's own `post_max_size` answers such a request too late to be the
 * contract: it has already buffered the body, and its 413 carries a framework
 * message. This runs first so an oversized JSON or multipart body is refused
 * with the API's own JSON error and the declared limit named once.
 */
class RejectOversizedRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        $limit = (int) config('uploads.request_bytes');
        $length = (int) $request->server('CONTENT_LENGTH');

        if ($limit > 0 && $length > $limit) {
            return response()->json([
                'message' => sprintf(
                    'The request body is too large. The limit is %d MB.',
                    intdiv($limit, 1024 * 1024),
                ),
            ], 413);
        }

        return $next($request);
    }
}
