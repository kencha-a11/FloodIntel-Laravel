<?php

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

uses(LazilyRefreshDatabase::class);

function verificationUrl(User $user, ?string $hash = null): string
{
    return URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
        'id' => $user->id,
        'hash' => $hash ?? sha1($user->getEmailForVerification()),
    ]);
}

it('sends a verification notification when registering', function () {
    Notification::fake();

    $this->postJson('/api/register', [
        'first_name' => 'Test',
        'last_name' => 'User',
        'email' => 'new@example.com',
        'contact_number' => '+15551234567',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertCreated();

    $user = User::where('email', 'new@example.com')->firstOrFail();

    expect($user->hasVerifiedEmail())->toBeFalse();

    Notification::assertSentTo($user, VerifyEmail::class);
});

it('verifies the email when visiting a valid signed link', function () {
    $user = User::factory()->unverified()->create();

    $this->getJson(verificationUrl($user))
        ->assertOk()
        ->assertJsonPath('message', 'Email verified successfully.');

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('rejects a signed link whose hash does not match the email', function () {
    $user = User::factory()->unverified()->create();

    $this->getJson(verificationUrl($user, sha1('other@example.com')))
        ->assertForbidden();

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('rejects a verification link without a valid signature', function () {
    $user = User::factory()->unverified()->create();

    $this->getJson("/api/email/verify/{$user->id}/".sha1($user->getEmailForVerification()))
        ->assertForbidden();

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('rejects an expired signed link', function () {
    $user = User::factory()->unverified()->create();

    $url = URL::temporarySignedRoute('verification.verify', now()->subMinute(), [
        'id' => $user->id,
        'hash' => sha1($user->getEmailForVerification()),
    ]);

    $this->getJson($url)->assertForbidden();

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('redirects browsers to the frontend after verifying the email', function () {
    config(['app.frontend_url' => 'https://floodintel-drrms.netlify.app']);

    $user = User::factory()->unverified()->create();

    $this->get(verificationUrl($user), ['Accept' => 'text/html'])
        ->assertRedirect('https://floodintel-drrms.netlify.app?email=verified');

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('redirects browsers to the frontend when the hash does not match', function () {
    config(['app.frontend_url' => 'https://floodintel-drrms.netlify.app']);

    $user = User::factory()->unverified()->create();

    $this->get(verificationUrl($user, sha1('other@example.com')), ['Accept' => 'text/html'])
        ->assertRedirect('https://floodintel-drrms.netlify.app?email=verification-failed');

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('redirects browsers to the frontend when the signature is invalid', function () {
    config(['app.frontend_url' => 'https://floodintel-drrms.netlify.app']);

    $user = User::factory()->unverified()->create();

    $this->get("/api/email/verify/{$user->id}/".sha1($user->getEmailForVerification()), ['Accept' => 'text/html'])
        ->assertRedirect('https://floodintel-drrms.netlify.app?email=verification-failed');
});

it('redirects browsers to the frontend when the verification link has expired', function () {
    config(['app.frontend_url' => 'https://floodintel-drrms.netlify.app']);

    $user = User::factory()->unverified()->create();

    $url = URL::temporarySignedRoute('verification.verify', now()->subMinute(), [
        'id' => $user->id,
        'hash' => sha1($user->getEmailForVerification()),
    ]);

    $this->get($url, ['Accept' => 'text/html'])
        ->assertRedirect('https://floodintel-drrms.netlify.app?email=verification-failed');
});

it('resends the verification notification for an unverified user', function () {
    Notification::fake();

    $user = User::factory()->unverified()->create();
    $token = $user->createToken('api_token')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/email/verification-notification')
        ->assertStatus(202);

    Notification::assertSentTo($user, VerifyEmail::class);
});

it('does not resend the notification for a verified user', function () {
    Notification::fake();

    $user = User::factory()->create();
    $token = $user->createToken('api_token')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/email/verification-notification')
        ->assertStatus(409);

    Notification::assertNothingSent();
});

it('blocks unverified users from verified routes', function () {
    $user = User::factory()->unverified()->create();
    $token = $user->createToken('api_token')->plainTextToken;

    $this->withToken($token)->getJson('/api/tokens')->assertForbidden();
});

it('allows verified users to access verified routes', function () {
    $user = User::factory()->create();
    $token = $user->createToken('api_token')->plainTextToken;

    $this->withToken($token)->getJson('/api/tokens')->assertOk();
});

it('requires authentication to resend the verification notification', function () {
    $this->postJson('/api/email/verification-notification')->assertUnauthorized();
});
