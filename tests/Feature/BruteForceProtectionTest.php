<?php

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

uses(LazilyRefreshDatabase::class);

it('locks out login after five failed attempts with a 429 and a Retry-After header', function () {
    $user = User::factory()->create();

    foreach (range(1, 5) as $ignored) {
        $this->postJson('/api/login', [
            'login' => $user->email,
            'password' => 'wrong-password',
        ])->assertUnprocessable();
    }

    $this->postJson('/api/login', [
        'login' => $user->email,
        'password' => 'wrong-password',
    ])->assertStatus(429)
        ->assertHeader('Retry-After');
});

it('clears the failure counter after a successful login', function () {
    $user = User::factory()->create();

    foreach (range(1, 4) as $ignored) {
        $this->postJson('/api/login', [
            'login' => $user->email,
            'password' => 'wrong-password',
        ])->assertUnprocessable();
    }

    $this->postJson('/api/login', [
        'login' => $user->email,
        'password' => 'password',
    ])->assertOk();

    foreach (range(1, 5) as $ignored) {
        $this->postJson('/api/login', [
            'login' => $user->email,
            'password' => 'wrong-password',
        ])->assertUnprocessable();
    }
});

it('rate limits login attempts per ip address', function () {
    RateLimiter::for('login', fn (Request $request) => Limit::perMinute(2)->by($request->ip()));

    $user = User::factory()->create();

    $credentials = ['login' => $user->email, 'password' => 'wrong-password'];

    $this->postJson('/api/login', $credentials)->assertStatus(422);
    $this->postJson('/api/login', $credentials)->assertStatus(422);
    $this->postJson('/api/login', $credentials)->assertStatus(429);
});

it('rate limits registration attempts per ip address', function () {
    RateLimiter::for('register', fn (Request $request) => Limit::perHour(2)->by($request->ip()));

    $payload = fn (int $i): array => [
        'first_name' => 'Test',
        'last_name' => 'User',
        'email' => "user{$i}@example.com",
        'contact_number' => "+1555000000{$i}",
        'password' => 'password',
        'password_confirmation' => 'password',
    ];

    $this->postJson('/api/register', $payload(1))->assertCreated();
    $this->postJson('/api/register', $payload(2))->assertCreated();
    $this->postJson('/api/register', $payload(3))->assertStatus(429);
});

it('logs a warning when a login lockout occurs', function () {
    Log::spy();

    $user = User::factory()->create();

    foreach (range(1, 5) as $ignored) {
        $this->postJson('/api/login', [
            'login' => $user->email,
            'password' => 'wrong-password',
        ]);
    }

    $this->postJson('/api/login', [
        'login' => $user->email,
        'password' => 'wrong-password',
    ])->assertStatus(429);

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message): bool => $message === 'Login lockout');
});
