<?php

use App\Enums\FloodLevel;
use App\Models\Barangay;
use App\Models\FloodReport;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
});

/**
 * Sanctum caches the resolved user for the test's application instance, so a
 * test that changes actors must forget the guards first.
 */
function idempotencyActAs(User $user): void
{
    app('auth')->forgetGuards();

    test()->withToken($user->createToken('t')->plainTextToken);
}

function idempotencyPayload(array $overrides = []): array
{
    $payload = array_merge([
        'client_uuid' => '0f8fad5b-d9cb-469f-a165-70867728950e',
        'flood_level' => FloodLevel::Knee->value,
        'latitude' => 14.5995,
        'longitude' => 120.9842,
        'photo' => testPhoto(),
        'reported_at' => now()->subMinutes(5)->toIso8601String(),
    ], $overrides);

    foreach ($overrides as $key => $value) {
        if ($value === null) {
            unset($payload[$key]);
        }
    }

    return $payload;
}

it('answers a replay with the stored report and stores no second photo', function () {
    Storage::fake('public');
    Http::fake();

    $tanod = User::factory()->tanod()->create();
    $token = $tanod->createToken('t')->plainTextToken;

    $first = $this->withToken($token)->post('/api/flood-reports', idempotencyPayload());

    $first->assertCreated();

    $report = FloodReport::firstOrFail();

    $second = $this->withToken($token)->post('/api/flood-reports', idempotencyPayload());

    $second->assertOk()
        ->assertJsonPath('data.id', $report->id)
        ->assertJsonPath('data.client_uuid', '0f8fad5b-d9cb-469f-a165-70867728950e')
        ->assertJsonPath('data.status', $report->status->value);

    expect(FloodReport::count())->toBe(1)
        ->and(Storage::disk('public')->allFiles('flood-reports'))->toHaveCount(1)
        ->and($report->client_uuid)->toBe('0f8fad5b-d9cb-469f-a165-70867728950e');
});

it('scopes the key to the reporter', function () {
    Storage::fake('public');
    Http::fake();

    $first = User::factory()->tanod()->create();
    $second = User::factory()->tanod()->create();

    idempotencyActAs($first);

    $firstResponse = $this->post('/api/flood-reports', idempotencyPayload())
        ->assertCreated();

    idempotencyActAs($second);

    $secondResponse = $this->post('/api/flood-reports', idempotencyPayload())
        ->assertCreated();

    expect($secondResponse->json('data.id'))->not->toBe($firstResponse->json('data.id'))
        ->and($secondResponse->json('data.user.id'))->toBe($second->id)
        ->and(FloodReport::count())->toBe(2);
});

it('creates a report for every submission when no key is sent', function () {
    Storage::fake('public');
    Http::fake();

    $tanod = User::factory()->tanod()->create();
    $token = $tanod->createToken('t')->plainTextToken;

    $this->withToken($token)
        ->post('/api/flood-reports', idempotencyPayload(['client_uuid' => null]))
        ->assertCreated();

    $this->withToken($token)
        ->post('/api/flood-reports', idempotencyPayload(['client_uuid' => null]))
        ->assertCreated();

    expect(FloodReport::count())->toBe(2);
});

it('accepts the queue fallback id that is not UUID-shaped', function () {
    Storage::fake('public');
    Http::fake();

    $tanod = User::factory()->tanod()->create();
    $token = $tanod->createToken('t')->plainTextToken;
    $key = 'local-1793534195191-a1b2c3d4';

    $this->withToken($token)
        ->post('/api/flood-reports', idempotencyPayload(['client_uuid' => $key]))
        ->assertCreated()
        ->assertJsonPath('data.client_uuid', $key);

    $this->withToken($token)
        ->post('/api/flood-reports', idempotencyPayload(['client_uuid' => $key]))
        ->assertOk();

    expect(FloodReport::count())->toBe(1);
});

it('refuses a duplicate key through the unique index, not only the look-up', function () {
    $key = 'replayed-around-the-check';
    $report = FloodReport::factory()->create(['client_uuid' => $key]);

    expect(fn () => FloodReport::factory()->create([
        'user_id' => $report->user_id,
        'barangay_id' => Barangay::factory()->create()->id,
        'client_uuid' => $key,
    ]))->toThrow(UniqueConstraintViolationException::class);

    expect(FloodReport::where('client_uuid', $key)->count())->toBe(1);
});
