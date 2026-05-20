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
        // Hayaan ang user kung naka-login na at may terms_accepted_at
        if (auth()->check() && !auth()->user()->terms_accepted_at) {
            return redirect()->route('server.terms.show');
        }
        return $next($request);
    }
}
