<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTeamAccess
{
    /**
     * Handle an incoming request.
     *
     * This middleware ensures that users can only access data from their own team.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        // Check if user is authenticated
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        // Super admin can bypass team checks
        if ($user->isSuperAdmin()) {
            $request->merge(['current_team_id' => null]);

            return $next($request);
        }

        // Check if user has a team
        if (! $user->team_id) {
            return response()->json([
                'success' => false,
                'message' => 'User tidak tergabung dalam tim',
            ], 403);
        }

        // Check if team is active
        if ($user->team && ! $user->team->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Tim Anda tidak aktif. Silakan hubungi administrator.',
            ], 403);
        }

        // Add team_id to request for easy access in controllers
        $request->merge(['current_team_id' => $user->team_id]);

        return $next($request);
    }
}
