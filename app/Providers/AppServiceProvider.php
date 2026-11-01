<?php

namespace App\Providers;

use App\Console\DestructiveCommandGuard;
use App\Enums\UserAbility;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

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
        $this->configureTrustedProxies();

        // FR-96: one rule for every path that sets a password. The framework
        // default (eight characters) must never apply by accident.
        Password::defaults(fn () => Password::min(12)->letters()->numbers()->max(255));

        ResetPassword::createUrlUsing(function (object $notifiable, string $token): string {
            $frontendUrl = rtrim((string) config('app.frontend_url'), '/');

            $base = $frontendUrl !== '' ? $frontendUrl.'/reset-password' : url('/reset-password');

            return $base.'?token='.$token.'&email='.urlencode($notifiable->getEmailForPasswordReset());
        });

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));

        // Named limiters for the authenticated surface (FR-99). They key on the
        // account so one signed-in client cannot spend another's allowance.
        RateLimiter::for('auth-status', fn (Request $request) => Limit::perMinute(60)->by(self::limiterKey($request)));
        RateLimiter::for('tokens', fn (Request $request) => Limit::perMinute(10)->by(self::limiterKey($request)));
        RateLimiter::for('admin-write', fn (Request $request) => Limit::perMinute(30)->by(self::limiterKey($request)));

        if ($this->app->runningInConsole()) {
            Event::listen(CommandStarting::class, DestructiveCommandGuard::class);
        }

        foreach (UserAbility::cases() as $ability) {
            Gate::define($ability->value, fn (User $user): bool => $user->hasAbility($ability));
        }
    }

    /**
     * Trust only the proxies the deployment names, and none when it names none.
     */
    private function configureTrustedProxies(): void
    {
        $trustedProxies = config('app.trusted_proxies', []);

        if ($trustedProxies !== []) {
            TrustProxies::at($trustedProxies);
        }
    }

    /**
     * Key a limiter on the account, falling back to the address before auth.
     */
    private static function limiterKey(Request $request): string
    {
        return (string) ($request->user()?->getKey() ?? $request->ip());
    }
}
