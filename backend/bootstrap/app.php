<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => \App\Http\Middleware\CheckRole::class,
            'log.user.activity' => \App\Http\Middleware\LogUserActivity::class,
            'super.admin' => \App\Http\Middleware\EnsureSuperAdmin::class,
            'registration.enabled' => \App\Http\Middleware\CheckRegistrationEnabled::class,
            'user.not_disabled' => \App\Http\Middleware\EnsureUserNotDisabled::class,
        ]);
        
        // Global middleware - applied to all routes
        $middleware->append(\App\Http\Middleware\SecurityHeadersMiddleware::class);
        
        // API middleware group
        $middleware->api(prepend: [
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->booted(function () {
        // Rate Limiting Configuration
        $isTesting = app()->environment('testing');

        // Strict limit for authentication endpoints (5 attempts per minute)
        RateLimiter::for('auth', function ($request) use ($isTesting) {
            if ($isTesting) {
                return Limit::none();
            }
            return Limit::perMinute(5)->by($request->ip() . '|' . $request->input('email', ''));
        });

        // General API rate limiting (60 requests per minute)
        RateLimiter::for('api', function ($request) use ($isTesting) {
            if ($isTesting) {
                return Limit::none();
            }
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Strict limit for WhatsApp operations (10 per minute)
        RateLimiter::for('whatsapp', function ($request) use ($isTesting) {
            if ($isTesting) {
                return Limit::none();
            }
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });

        // Upload/report generation limit (10 per minute)
        RateLimiter::for('reports', function ($request) use ($isTesting) {
            if ($isTesting) {
                return Limit::none();
            }
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });
    })
    ->create();
