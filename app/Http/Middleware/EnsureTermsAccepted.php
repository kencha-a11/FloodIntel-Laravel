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
    public function handle(Request $request, Closure $next): Response
    {
        // Mas inirerekomenda sa Laravel ang $request->user() kapag nasa loob ng middleware context
        $user = $request->user();

        if ($user && is_null($user->terms_accepted_at)) {

            // Check kung ang request ay nanggaling sa API (o kailangan ng JSON)
            if ($request->expectsJson() || $request->is('api/*')) {

                // 🌟 SAFETY CHECK: Huwag harangan ang mismong endpoint na nag-a-accept ng terms!
                // Baguhin ang URI string kung iba ang pwesto ng route mo (e.g., 'api/auth/terms/accept')
                if ($request->is('*/terms/accept')) {
                    return $next($request);
                }

                return response()->json([
                    'message' => 'Terms and conditions not accepted.',
                    'requires_terms_acceptance' => true,
                ], 428); // 428 Precondition Required (Napakagandang choice ng HTTP status code!)
            }

            // Kung hindi API, ibig sabihin ay Web (Blade)
            // Siguraduhin na hindi mag-i-infinite loop sa pamamagitan ng pag-bypass sa terms routes
            if (!$request->routeIs('server.terms.*')) {
                return redirect()->route('server.terms.show');
            }
        }

        return $next($request);
    }
}
