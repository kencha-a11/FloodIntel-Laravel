<?php

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

uses(LazilyRefreshDatabase::class);

function perAccount(string $name, int $perMinute): void
{
    RateLimiter::for($name, fn (Request $request) => Limit::perMinute($perMinute)
        ->by((string) $request->user()?->getKey()));
}

it('puts a limiter on every authenticated route', function () {
    $authenticated = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => in_array('auth:sanctum', $route->gatherMiddleware(), true));

    expect($authenticated)->not->toBeEmpty();

    $unthrottled = $authenticated
        ->reject(fn ($route) => collect($route->gatherMiddleware())
            ->contains(fn (string $middleware) => str_starts_with($middleware, 'throttle:')))
        ->map(fn ($route) => $route->methods()[0].' '.$route->uri())
        ->values()
        ->all();

    expect($unthrottled)->toBe([]);
});

it('throttles the account status surface with a Retry-After header', function () {
    perAccount('auth-status', 2);

    $user = User::factory()->create();
    $token = $user->createToken('t')->plainTextToken;

    $this->withToken($token)->getJson('/api/user')->assertOk();
    $this->withToken($token)->getJson('/api/user')->assertOk();
    $this->withToken($token)->getJson('/api/user')
        ->assertStatus(429)
        ->assertHeader('Retry-After');
});

it('keys every named limiter on the account, not the address', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();

    foreach (['auth-status', 'tokens', 'admin-write'] as $name) {
        $limiter = RateLimiter::limiter($name);

        $keyFor = fn (User $user): string => $limiter(
            Request::create('/api/user')->setUserResolver(fn () => $user),
        )->key;

        expect($keyFor($first))->toBe((string) $first->getKey())
            ->and($keyFor($second))->toBe((string) $second->getKey());
    }
});

it('throttles token management per account', function () {
    perAccount('tokens', 2);

    $user = User::factory()->create();
    $token = $user->createToken('t')->plainTextToken;

    $this->withToken($token)->getJson('/api/tokens')->assertOk();
    $this->withToken($token)->getJson('/api/tokens')->assertOk();
    $this->withToken($token)->getJson('/api/tokens')
        ->assertStatus(429)
        ->assertHeader('Retry-After');
});

it('throttles user administration and barangay reads', function () {
    perAccount('admin-write', 1);

    $officer = User::factory()->officer()->create();
    $token = $officer->createToken('t')->plainTextToken;

    $this->withToken($token)->getJson('/api/barangays')->assertOk();
    $this->withToken($token)->getJson('/api/barangays')
        ->assertStatus(429)
        ->assertHeader('Retry-After');
});
