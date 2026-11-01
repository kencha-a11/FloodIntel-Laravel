<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password as PasswordBroker;

uses(LazilyRefreshDatabase::class);

function passwordPolicyPayload(string $password, string $email = 'policy-probe@example.com'): array
{
    return [
        'first_name' => 'Policy',
        'last_name' => 'Probe',
        'email' => $email,
        'contact_number' => '+1555777'.substr(md5($email), 0, 4),
        'password' => $password,
        'role' => UserRole::MdrrmoStaff->value,
    ];
}

it('rejects a password shorter than twelve characters on create', function () {
    Notification::fake();
    $officer = User::factory()->officer()->create();

    $this->withToken($officer->createToken('t')->plainTextToken)
        ->postJson('/api/users', passwordPolicyPayload('Passw0rd123'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['password']);

    expect(User::where('email', 'policy-probe@example.com')->exists())->toBeFalse();
});

it('rejects a twelve-character password without a digit on create', function () {
    Notification::fake();
    $officer = User::factory()->officer()->create();

    $this->withToken($officer->createToken('t')->plainTextToken)
        ->postJson('/api/users', passwordPolicyPayload('lettersletters'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['password']);
});

it('rejects a twelve-character password without a letter on create', function () {
    Notification::fake();
    $officer = User::factory()->officer()->create();

    $this->withToken($officer->createToken('t')->plainTextToken)
        ->postJson('/api/users', passwordPolicyPayload('123456789012'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['password']);
});

it('accepts a compliant password on create', function () {
    Notification::fake();
    $officer = User::factory()->officer()->create();

    $this->withToken($officer->createToken('t')->plainTextToken)
        ->postJson('/api/users', passwordPolicyPayload('Passw0rd1234'))
        ->assertCreated();

    $user = User::where('email', 'policy-probe@example.com')->firstOrFail();

    expect(Hash::check('Passw0rd1234', $user->password))->toBeTrue();
});

it('accepts a 255-character passphrase and rejects one character more', function () {
    Notification::fake();
    $officer = User::factory()->officer()->create();
    $token = $officer->createToken('t')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/users', passwordPolicyPayload(str_repeat('a', 254).'1', 'max@example.com'))
        ->assertCreated();

    $this->withToken($token)
        ->postJson('/api/users', passwordPolicyPayload(str_repeat('a', 255).'1', 'over@example.com'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['password']);
});

it('rejects a password below the policy on reset', function () {
    $user = User::factory()->create(['password' => 'old-password1']);
    $token = PasswordBroker::broker()->createToken($user);

    $this->postJson('/api/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'short1',
        'password_confirmation' => 'short1',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['password']);

    expect(Hash::check('old-password1', $user->fresh()->password))->toBeTrue();
});

it('applies the same policy on reset', function () {
    $user = User::factory()->create(['password' => 'old-password1']);
    $token = PasswordBroker::broker()->createToken($user);

    $this->postJson('/api/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'New-password1',
        'password_confirmation' => 'New-password1',
    ])->assertOk();

    expect(Hash::check('New-password1', $user->fresh()->password))->toBeTrue();
});
