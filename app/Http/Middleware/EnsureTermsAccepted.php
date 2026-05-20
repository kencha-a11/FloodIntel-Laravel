<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTermsAccepted
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next)
    {
        $user = auth()->user();

        if ($user && is_null($user->terms_accepted_at)) {

            // Check kung ang request ay nanggaling sa API (o kailangan ng JSON)
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => 'Terms and conditions not accepted.',
                    'requires_terms_acceptance' => true,
                ], 428);
            }

            // Kung hindi API, ibig sabihin ay Web (Blade)
            // Siguraduhin na hindi mag-i-infinite loop
            if (!$request->routeIs('server.terms.*')) {
                return redirect()->route('server.terms.show');
            }
        }

        return $next($request);
    }
}
