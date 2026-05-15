<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserNotDisabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->is_disabled) {
            $user->tokens()->delete();

            return response()->json([
                'success' => false,
                'message' => 'Akun anda telah dinonaktifkan. Silakan hubungi manager.',
            ], 403);
        }

        return $next($request);
    }
}
