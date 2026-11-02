<?php

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('returns 401 when listing sessions without a token', function () {
    $this->getJson('/api/sessions')->assertUnauthorized();
});

it('returns the authenticated user login sessions with a current marker', function () {
    $user = User::factory()->create();

    $first = $this->postJson('/api/login', [
        'login' => $user->email,
        'password' => 'password',
    ])->json('token');

    $firstId = $user->tokens()->orderBy('id')->first()->id;

    $second = $this->postJson('/api/login', [
        'login' => $user->email,
        'password' => 'password',
    ])->json('token');

    $secondId = $user->tokens()->orderByDesc('id')->first()->id;

    $response = $this->withToken($first)->getJson('/api/sessions');

    $response->assertOk()
        ->assertJsonCount(2, 'data');

    $rows = collect($response->json('data'));

    expect($rows->where('is_current', true)->count())->toBe(1);
    expect($rows->firstWhere('id', $firstId)['is_current'])->toBeTrue();
    expect($rows->firstWhere('id', $secondId)['is_current'])->toBeFalse();
});

it('returns the expected session fields and never the token secret', function () {
    $user = User::factory()->create();

    $token = $this->postJson('/api/login', [
        'login' => $user->email,
        'password' => 'password',
    ])->json('token');

    $response = $this->withToken($token)->getJson('/api/sessions');

    $response->assertOk()
        ->assertJsonCount(1, 'data');

    $row = $response->json('data.0');

    expect(array_keys($row))->toBe([
        'id',
        'name',
        'ip_address',
        'user_agent',
        'logged_in_at',
        'last_used_at',
        'is_current',
    ]);
});

it('excludes other users, failed logins, and api tokens without a login event', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $this->postJson('/api/login', [
        'login' => $user->email,
        'password' => 'wrong-password',
    ])->assertUnprocessable();

    $user->createToken('api_token');

    $this->postJson('/api/login', [
        'login' => $other->email,
        'password' => 'password',
    ])->assertOk();

    $userLogin = $this->postJson('/api/login', [
        'login' => $user->email,
        'password' => 'password',
    ])->json('token');

    $response = $this->withToken($userLogin)->getJson('/api/sessions');

    $response->assertOk()
        ->assertJsonCount(1, 'data');
});

it('excludes an expired session token', function () {
    $user = User::factory()->create();

    $expired = $this->postJson('/api/login', [
        'login' => $user->email,
        'password' => 'password',
    ])->json('token');

    $expiredId = $user->tokens()->orderBy('id')->first()->id;
    $user->tokens()->whereKey($expiredId)->update(['expires_at' => now()->subHour()]);

    $live = $this->postJson('/api/login', [
        'login' => $user->email,
        'password' => 'password',
    ])->json('token');

    $liveId = $user->tokens()->orderByDesc('id')->first()->id;

    $response = $this->withToken($live)->getJson('/api/sessions');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $liveId);
});

it('revokes a listed session through the existing token endpoint', function () {
    $user = User::factory()->create();

    $first = $this->postJson('/api/login', [
        'login' => $user->email,
        'password' => 'password',
    ])->json('token');

    $firstId = $user->tokens()->orderBy('id')->first()->id;

    $second = $this->postJson('/api/login', [
        'login' => $user->email,
        'password' => 'password',
    ])->json('token');

    $secondId = $user->tokens()->orderByDesc('id')->first()->id;

    $this->withToken($first)
        ->deleteJson("/api/tokens/{$secondId}")
        ->assertNoContent();

    $this->assertDatabaseMissing('personal_access_tokens', [
        'id' => $secondId,
    ]);

    auth()->forgetGuards();
    auth('web')->logout();
    $this->withToken($second)->getJson('/api/user')->assertUnauthorized();

    auth()->forgetGuards();
    auth('web')->logout();
    $this->withToken($first)->getJson('/api/user')->assertOk();
});
