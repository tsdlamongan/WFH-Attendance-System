<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Config;

class CheckRegistrationEnabled
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response|RedirectResponse)  $next
     * @return Response|RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        if (! Config::get('app.registration.enabled', true)) {
            return response()->json([
                'success' => false,
                'message' => 'Registration is currently disabled',
                'errors' => [
                    'registration' => ['Pendaftaran akun baru sedang dinonaktifkan'],
                ],
            ], 403);
        }

        return $next($request);
    }
}
