<?php

use App\Models\LoginEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\PersonalAccessToken;

uses(LazilyRefreshDatabase::class);

it('records a successful login with user, token, ip, user agent, and outcome', function () {
    $user = User::factory()->create();

    $this->withHeaders(['User-Agent' => 'FloodIntelTest/1.0'])
        ->postJson('/api/login', [
            'login' => $user->email,
            'password' => 'password',
        ])
        ->assertOk();

    $event = LoginEvent::query()->sole();

    expect($event->user_id)->toBe($user->id)
        ->and($event->personal_access_token_id)->toBe(
            PersonalAccessToken::query()->where('tokenable_id', $user->id)->value('id'),
        )
        ->and($event->outcome)->toBe('success')
        ->and($event->ip_address)->toBe('127.0.0.1')
        ->and($event->user_agent)->toBe('FloodIntelTest/1.0');
});

it('records a failed login without the identifier, the password, or any user or token', function () {
    $user = User::factory()->create();

    $this->withHeaders(['User-Agent' => 'FloodIntelTest/1.0'])
        ->postJson('/api/login', [
            'login' => $user->email,
            'password' => 'wrong-password',
        ])
        ->assertUnprocessable();

    $event = LoginEvent::query()->sole();

    expect($event->user_id)->toBeNull()
        ->and($event->personal_access_token_id)->toBeNull()
        ->and($event->outcome)->toBe('failed')
        ->and($event->ip_address)->toBe('127.0.0.1')
        ->and($event->user_agent)->toBe('FloodIntelTest/1.0');

    $stored = json_encode($event->getAttributes());

    expect($stored)->not->toContain($user->email)
        ->and($stored)->not->toContain('wrong-password');
});

it('purges expired tokens and keeps live ones', function () {
    $user = User::factory()->create();

    $expired = $user->createToken('expired', ['*'], now()->subDay())->accessToken;
    $live = $user->createToken('live', ['*'], now()->addDay())->accessToken;

    Artisan::call('sanctum:prune-expired', ['--hours' => 0]);

    $this->assertDatabaseMissing('personal_access_tokens', ['id' => $expired->id]);
    $this->assertDatabaseHas('personal_access_tokens', ['id' => $live->id]);
});

it('schedules the expired-token purge daily', function () {
    $this->artisan('schedule:list')
        ->assertSuccessful()
        ->expectsOutputToContain('sanctum:prune-expired');
});
