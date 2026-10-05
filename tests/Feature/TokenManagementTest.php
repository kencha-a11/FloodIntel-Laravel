<?php

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('returns the user tokens when listing tokens', function () {
    $user = User::factory()->create();
    $user->createToken('first_token');
    $user->createToken('second_token');

    $response = $this->withToken($user->createToken('request_token')->plainTextToken)->getJson('/api/tokens');

    $response->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonFragment(['name' => 'first_token'])
        ->assertJsonFragment(['name' => 'second_token']);
});

it('returns 401 when listing tokens without a token', function () {
    $response = $this->getJson('/api/tokens');

    $response->assertUnauthorized();
});

it('creates a token when storing with valid data', function () {
    $user = User::factory()->create();

    $response = $this->withToken($user->createToken('request_token')->plainTextToken)
        ->postJson('/api/tokens', [
            'name' => 'new_token',
            'abilities' => ['server:update'],
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.name', 'new_token')
        ->assertJsonPath('data.abilities', ['server:update'])
        ->assertJsonStructure(['data' => ['id', 'name', 'abilities'], 'token']);

    $this->assertDatabaseHas('personal_access_tokens', [
        'name' => 'new_token',
        'tokenable_id' => $user->id,
    ]);
});

it('creates a token with all abilities when no abilities are provided', function () {
    $user = User::factory()->create();

    $response = $this->withToken($user->createToken('request_token')->plainTextToken)
        ->postJson('/api/tokens', ['name' => 'new_token']);

    $response->assertCreated()
        ->assertJsonPath('data.abilities', ['*']);
});

it('returns 422 when creating a token without a name', function () {
    $user = User::factory()->create();

    $response = $this->withToken($user->createToken('request_token')->plainTextToken)
        ->postJson('/api/tokens', []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

it('revokes a token when deleting an owned token', function () {
    $user = User::factory()->create();
    $token = $user->createToken('token_to_revoke');
    $requestToken = $user->createToken('request_token');

    $response = $this->withToken($requestToken->plainTextToken)
        ->deleteJson("/api/tokens/{$token->accessToken->id}");

    $response->assertNoContent();

    $this->assertDatabaseMissing('personal_access_tokens', [
        'id' => $token->accessToken->id,
    ]);
    $this->assertDatabaseHas('personal_access_tokens', [
        'id' => $requestToken->accessToken->id,
    ]);
});

it('returns 404 when deleting a token owned by another user', function () {
    $otherUser = User::factory()->create();
    $token = $otherUser->createToken('other_token');

    $user = User::factory()->create();
    $requestToken = $user->createToken('request_token');

    $response = $this->withToken($requestToken->plainTextToken)
        ->deleteJson("/api/tokens/{$token->accessToken->id}");

    $response->assertNotFound();

    $this->assertDatabaseHas('personal_access_tokens', [
        'id' => $token->accessToken->id,
    ]);
});

it('returns 404 when deleting a token that does not exist', function () {
    $user = User::factory()->create();

    $response = $this->withToken($user->createToken('request_token')->plainTextToken)
        ->deleteJson('/api/tokens/9999');

    $response->assertNotFound();
});

it('revokes all tokens when deleting all tokens', function () {
    $user = User::factory()->create();
    $user->createToken('first_token');
    $user->createToken('second_token');
    $requestToken = $user->createToken('request_token');

    $response = $this->withToken($requestToken->plainTextToken)->deleteJson('/api/tokens');

    $response->assertNoContent();

    $this->assertDatabaseMissing('personal_access_tokens', [
        'tokenable_id' => $user->id,
    ]);
});
