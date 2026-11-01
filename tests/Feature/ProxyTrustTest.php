<?php

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

uses(LazilyRefreshDatabase::class);

it('binds the login lockout to the real client when X-Forwarded-For is forged', function () {
    $user = User::factory()->create();

    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/login', [
            'login' => $user->email,
            'password' => 'wrong-password',
        ], ['X-Forwarded-For' => '198.51.100.'.$attempt])
            ->assertUnprocessable();
    }

    $this->postJson('/api/login', [
        'login' => $user->email,
        'password' => 'wrong-password',
    ], ['X-Forwarded-For' => '198.51.100.99'])
        ->assertStatus(429);
});

it('trusts no proxy when none is configured', function () {
    expect(config('app.trusted_proxies'))->toBe([]);

    $request = Request::create('/api/login', 'POST', server: ['REMOTE_ADDR' => '203.0.113.9']);
    $request->headers->set('X-Forwarded-For', '198.51.100.7');

    $this->app->make(TrustProxies::class)->handle($request, fn (Request $next) => $next);

    expect($request->ip())->toBe('203.0.113.9');
});
