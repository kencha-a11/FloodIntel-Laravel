<?php

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('does not expose a public registration endpoint', function () {
    $this->postJson('/api/register', [
        'first_name' => 'Test',
        'last_name' => 'User',
        'email' => 'test@example.com',
        'contact_number' => '+15551234567',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();

    $this->assertDatabaseMissing('users', [
        'email' => 'test@example.com',
    ]);
});

it('returns a token when logging in with valid credentials', function () {
    $user = User::factory()->create();

    $response = $this->postJson('/api/login', [
        'login' => $user->email,
        'password' => 'password',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['user', 'token'])
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonPath('user.role', $user->role->value);
});

it('returns a token when logging in with a valid contact number', function () {
    $user = User::factory()->create();

    $response = $this->postJson('/api/login', [
        'login' => $user->contact_number,
        'password' => 'password',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['user', 'token'])
        ->assertJsonPath('user.id', $user->id);
});

it('returns 422 when logging in with invalid credentials', function () {
    $user = User::factory()->create();

    $response = $this->postJson('/api/login', [
        'login' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['login']);
});

it('returns 422 when logging in with a contact number and invalid credentials', function () {
    $user = User::factory()->create();

    $response = $this->postJson('/api/login', [
        'login' => $user->contact_number,
        'password' => 'wrong-password',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['login']);
});

it('returns the authenticated user with role and abilities when a valid token is provided', function () {
    $user = User::factory()->officer()->create();
    $token = $user->createToken('api_token')->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/user');

    $response->assertOk()
        ->assertJsonPath('id', $user->id)
        ->assertJsonPath('email', $user->email)
        ->assertJsonPath('role', $user->role->value)
        ->assertJsonPath('interface', $user->role->interface()->value)
        ->assertJsonStructure(['abilities']);
});

it('returns 401 when accessing the user endpoint without a token', function () {
    $response = $this->getJson('/api/user');

    $response->assertUnauthorized();
});

it('returns 401 instead of a server error when the client does not request json', function () {
    $response = $this->get('/api/user');

    $response->assertUnauthorized()
        ->assertJsonPath('message', 'Unauthenticated.');
});

it('returns the assigned barangay in the authenticated user payload', function () {
    $user = User::factory()->captain()->create();
    $token = $user->createToken('api_token')->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/user');

    $response->assertOk()
        ->assertJsonPath('barangay.id', $user->barangay_id)
        ->assertJsonPath('barangay.name', $user->barangay->name);
});

it('returns the assigned barangay in the login payload', function () {
    $user = User::factory()->captain()->create();

    $response = $this->postJson('/api/login', [
        'login' => $user->email,
        'password' => 'password',
    ]);

    $response->assertOk()
        ->assertJsonPath('user.barangay.id', $user->barangay_id)
        ->assertJsonPath('user.barangay.name', $user->barangay->name);
});

it('returns 401 when accessing the user endpoint with an invalid token', function () {
    $response = $this->withToken('invalid-token')->getJson('/api/user');

    $response->assertUnauthorized();
});

it('revokes the current token when logging out', function () {
    $user = User::factory()->create();
    $token = $user->createToken('api_token');

    $response = $this->withToken($token->plainTextToken)->postJson('/api/logout');

    $response->assertStatus(204);

    $this->assertDatabaseMissing('personal_access_tokens', [
        'id' => $token->accessToken->id,
    ]);
});

it('returns 401 when logging out without a token', function () {
    $response = $this->postJson('/api/logout');

    $response->assertUnauthorized();
});
