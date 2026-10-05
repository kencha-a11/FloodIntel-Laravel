<?php

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('creates a user and returns a token when registering with valid data', function () {
    $response = $this->postJson('/api/register', [
        'first_name' => 'Test',
        'last_name' => 'User',
        'email' => 'test@example.com',
        'contact_number' => '+15551234567',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertCreated()
        ->assertJsonStructure(['user', 'token'])
        ->assertJsonPath('user.first_name', 'Test')
        ->assertJsonPath('user.last_name', 'User')
        ->assertJsonPath('user.email', 'test@example.com')
        ->assertJsonPath('user.contact_number', '+15551234567');

    $this->assertDatabaseHas('users', [
        'email' => 'test@example.com',
    ]);
});

it('returns 422 when registering with missing required fields', function () {
    $response = $this->postJson('/api/register', []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['first_name', 'last_name', 'email', 'contact_number', 'password']);
});

it('returns 422 when registering with an already registered email', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $response = $this->postJson('/api/register', [
        'first_name' => 'Test',
        'last_name' => 'User',
        'email' => 'taken@example.com',
        'contact_number' => '+15557654321',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);
});

it('returns 422 when registering with an already registered contact number', function () {
    User::factory()->create(['contact_number' => '+15551234567']);

    $response = $this->postJson('/api/register', [
        'first_name' => 'Test',
        'last_name' => 'User',
        'email' => 'test@example.com',
        'contact_number' => '+15551234567',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['contact_number']);
});

it('returns a token when logging in with valid credentials', function () {
    $user = User::factory()->create();

    $response = $this->postJson('/api/login', [
        'login' => $user->email,
        'password' => 'password',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['user', 'token'])
        ->assertJsonPath('user.id', $user->id);
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

it('returns the authenticated user when a valid token is provided', function () {
    $user = User::factory()->create();
    $token = $user->createToken('api_token')->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/user');

    $response->assertOk()
        ->assertJsonPath('id', $user->id)
        ->assertJsonPath('email', $user->email);
});

it('returns 401 when accessing the user endpoint without a token', function () {
    $response = $this->getJson('/api/user');

    $response->assertUnauthorized();
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
