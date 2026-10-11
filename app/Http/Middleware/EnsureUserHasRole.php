<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * Ensure the authenticated user holds one of the given roles.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $allowed = array_map(fn (string $role): UserRole => UserRole::from($role), $roles);

        if (! $user->hasRole(...$allowed)) {
            abort(403);
        }

        return $next($request);
    }
}
