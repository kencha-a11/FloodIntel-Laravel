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

        // 1. Blade Template Approach (Pang-test)
        // I-uncomment ito kung gusto mong gumamit ng redirect (Blade/Web)
        if (auth()->check() && !$user->terms_accepted_at) {
            return redirect()->route('server.terms.show');
        }


        // 2. React / React Native / API Approach (Production)
        // Ito ang gamitin para sa iyong main project
        // if ($user && !$user->terms_accepted_at) {
        //     return response()->json([
        //         'message' => 'Terms and conditions not accepted.',
        //         'requires_terms_acceptance' => true,
        //     ], 428);
        // }

        return $next($request);
    }
}
