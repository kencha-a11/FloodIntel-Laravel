<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

uses(LazilyRefreshDatabase::class);

it('sends a password reset notification for a registered email', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->postJson('/api/forgot-password', ['email' => $user->email])
        ->assertOk();

    Notification::assertSentTo($user, ResetPassword::class);
});

it('points the reset link at the frontend url', function () {
    Notification::fake();

    config(['app.frontend_url' => 'https://app.example.com']);

    $user = User::factory()->create();

    $this->postJson('/api/forgot-password', ['email' => $user->email])->assertOk();

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
        $url = (string) $notification->toMail($user)->actionUrl;

        return str_starts_with($url, 'https://app.example.com/reset-password?')
            && str_contains($url, 'token=')
            && str_contains($url, 'email='.urlencode($user->email));
    });
});

it('returns a generic success response for an unknown email', function () {
    Notification::fake();

    $this->postJson('/api/forgot-password', ['email' => 'nobody@example.com'])
        ->assertOk();

    Notification::assertNothingSent();
});

it('requires a valid email to request a reset link', function () {
    $this->postJson('/api/forgot-password', ['email' => 'not-an-email'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);
});

it('resets the password with a valid token and revokes existing tokens', function () {
    $user = User::factory()->create(['password' => 'old-password']);
    $token = Password::broker()->createToken($user);

    $user->createToken('api_token');

    $this->postJson('/api/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ])->assertOk();

    expect(Hash::check('new-password', $user->fresh()->password))->toBeTrue()
        ->and($user->tokens()->count())->toBe(0);

    $this->postJson('/api/login', [
        'login' => $user->email,
        'password' => 'new-password',
    ])->assertOk();
});

it('rejects an invalid reset token', function () {
    $user = User::factory()->create();

    $this->postJson('/api/reset-password', [
        'token' => 'invalid-token',
        'email' => $user->email,
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ])->assertStatus(422);
});

it('rejects a mismatched password confirmation', function () {
    $user = User::factory()->create();
    $token = Password::broker()->createToken($user);

    $this->postJson('/api/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'new-password',
        'password_confirmation' => 'different-password',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['password']);
});

it('returns 422 when required reset fields are missing', function () {
    $this->postJson('/api/reset-password', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['token', 'email', 'password']);
});
