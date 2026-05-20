<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

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
        // Define ang rate limiter para sa registration
        RateLimiter::for('registration', function (Request $request) {
            // Papayagan lang ang 3 registration requests bawat 1 minuto bawat IP address
            return Limit::perMinute(3)->by($request->ip());
        });

        // Login Limiter
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by($request->email . $request->ip());
        });
    }
}