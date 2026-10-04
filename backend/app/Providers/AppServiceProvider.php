<?php

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Every {id} / {userId} route parameter is a database id and is received as an `int` by the
        // controllers. Only accept up to 18 digits (always fits a 64-bit int): "/teams/abc" or an
        // out-of-range id is then a plain 404 instead of a TypeError (HTTP 500).
        Route::pattern('id', '[0-9]{1,18}');
        Route::pattern('userId', '[0-9]{1,18}');
    }
}
