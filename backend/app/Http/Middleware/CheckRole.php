<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $role): Response
    {
        if (! auth()->check()) {
            abort(401, 'Unauthenticated.');
        }

        $user = auth()->user();

        // Super admin can access all routes
        if ($user->isSuperAdmin()) {
            return $next($request);
        }

        // Fail closed: a role this middleware does not know (a typo such as "role:admin") must
        // lock the route for everyone but the super admin, never silently open it.
        $allowed = match ($role) {
            'manager' => $user->isManager(),
            'employee' => $user->isEmployee(),
            default => false,
        };

        if (! $allowed) {
            abort(403, match ($role) {
                'manager' => 'Unauthorized. Manager role required.',
                'employee' => 'Unauthorized. Employee role required.',
                default => 'Unauthorized. Role not permitted.',
            });
        }

        return $next($request);
    }
}
