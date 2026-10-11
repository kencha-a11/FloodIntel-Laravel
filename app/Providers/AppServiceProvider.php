<?php

namespace App\Providers;

use App\Enums\UserAbility;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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
        ResetPassword::createUrlUsing(function (object $notifiable, string $token): string {
            $frontendUrl = rtrim((string) config('app.frontend_url'), '/');

            $base = $frontendUrl !== '' ? $frontendUrl.'/reset-password' : url('/reset-password');

            return $base.'?token='.$token.'&email='.urlencode($notifiable->getEmailForPasswordReset());
        });

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));

        foreach (UserAbility::cases() as $ability) {
            Gate::define($ability->value, fn (User $user): bool => $user->hasAbility($ability));
        }
    }
}
