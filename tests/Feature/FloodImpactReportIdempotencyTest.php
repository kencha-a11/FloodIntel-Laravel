<?php

use App\Models\Barangay;
use App\Models\FloodEvent;
use App\Models\FloodImpactReport;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
});

/**
 * Sanctum caches the resolved user for the test's application instance, so a
 * test that changes actors must forget the guards first.
 */
function impactIdempotencyActAs(User $user): void
{
    app('auth')->forgetGuards();

    test()->withToken($user->createToken('t')->plainTextToken);
}

function impactIdempotencyPayload(FloodEvent $event, array $overrides = []): array
{
    $payload = array_merge([
        'client_uuid' => '8c1d0a52-4a4f-4f4d-9a0c-5d1d6a1f0001',
        'event_id' => $event->id,
        'families' => 12,
        'individuals' => 48,
        'remarks' => 'Water reached the second step of the barangay hall.',
    ], $overrides);

    foreach ($overrides as $key => $value) {
        if ($value === null) {
            unset($payload[$key]);
        }
    }

    return $payload;
}

function impactCaptain(Barangay $barangay): User
{
    return User::factory()->captain()->forBarangay($barangay)->create();
}

it('answers a replay with the stored report and stores no second row', function () {
    $captain = impactCaptain(Barangay::factory()->create());
    $event = FloodEvent::factory()->create();

    impactIdempotencyActAs($captain);

    $first = $this->postJson('/api/flood-impact-reports', impactIdempotencyPayload($event));

    $first->assertCreated();

    $report = FloodImpactReport::firstOrFail();

    $second = $this->postJson('/api/flood-impact-reports', impactIdempotencyPayload($event));

    $second->assertOk()
        ->assertJsonPath('data.id', $report->id)
        ->assertJsonPath('data.client_uuid', '8c1d0a52-4a4f-4f4d-9a0c-5d1d6a1f0001')
        ->assertJsonPath('data.status', 'submitted');

    expect(FloodImpactReport::count())->toBe(1)
        ->and($report->client_uuid)->toBe('8c1d0a52-4a4f-4f4d-9a0c-5d1d6a1f0001');
});

it('scopes the key to the reporter', function () {
    $event = FloodEvent::factory()->create();
    $first = impactCaptain(Barangay::factory()->create());
    $second = impactCaptain(Barangay::factory()->create());

    impactIdempotencyActAs($first);

    $firstResponse = $this->postJson('/api/flood-impact-reports', impactIdempotencyPayload($event))
        ->assertCreated();

    impactIdempotencyActAs($second);

    $secondResponse = $this->postJson('/api/flood-impact-reports', impactIdempotencyPayload($event))
        ->assertCreated();

    expect($secondResponse->json('data.id'))->not->toBe($firstResponse->json('data.id'))
        ->and($secondResponse->json('data.user_id'))->toBe($second->id)
        ->and(FloodImpactReport::count())->toBe(2);
});

it('creates a report for every submission when no key is sent', function () {
    $captain = impactCaptain(Barangay::factory()->create());
    $event = FloodEvent::factory()->create();

    impactIdempotencyActAs($captain);

    $this->postJson('/api/flood-impact-reports', impactIdempotencyPayload($event, ['client_uuid' => null]))
        ->assertCreated();

    $this->postJson('/api/flood-impact-reports', impactIdempotencyPayload($event, ['client_uuid' => null]))
        ->assertCreated();

    expect(FloodImpactReport::count())->toBe(2);
});

it('accepts the queue fallback id that is not UUID-shaped', function () {
    $captain = impactCaptain(Barangay::factory()->create());
    $event = FloodEvent::factory()->create();
    $key = 'local-1793534195191-a1b2c3d4';

    impactIdempotencyActAs($captain);

    $this->postJson('/api/flood-impact-reports', impactIdempotencyPayload($event, ['client_uuid' => $key]))
        ->assertCreated()
        ->assertJsonPath('data.client_uuid', $key);

    $this->postJson('/api/flood-impact-reports', impactIdempotencyPayload($event, ['client_uuid' => $key]))
        ->assertOk();

    expect(FloodImpactReport::count())->toBe(1);
});

it('answers a replay of a correction from the stored report without superseding twice', function () {
    $barangay = Barangay::factory()->create();
    $captain = impactCaptain($barangay);
    $event = FloodEvent::factory()->create();
    $previous = FloodImpactReport::factory()->for($captain)->create([
        'barangay_id' => $barangay->id,
        'event_id' => $event->id,
        'families' => 12,
        'individuals' => 48,
    ]);

    impactIdempotencyActAs($captain);

    $payload = impactIdempotencyPayload($event, [
        'families' => 20,
        'individuals' => 80,
        'supersedes_id' => $previous->id,
    ]);

    $first = $this->postJson('/api/flood-impact-reports', $payload)
        ->assertCreated()
        ->assertJsonPath('data.supersedes_id', $previous->id);

    expect($previous->refresh()->status->value)->toBe('superseded');

    $this->postJson('/api/flood-impact-reports', $payload)
        ->assertOk()
        ->assertJsonPath('data.id', $first->json('data.id'))
        ->assertJsonPath('data.supersedes_id', $previous->id);

    expect(FloodImpactReport::count())->toBe(2)
        ->and($previous->refresh()->status->value)->toBe('superseded');
});

it('still refuses a fresh key that corrects an already superseded report', function () {
    $barangay = Barangay::factory()->create();
    $captain = impactCaptain($barangay);
    $event = FloodEvent::factory()->create();
    $previous = FloodImpactReport::factory()->for($captain)->create([
        'barangay_id' => $barangay->id,
        'event_id' => $event->id,
        'status' => 'superseded',
    ]);

    impactIdempotencyActAs($captain);

    $this->postJson('/api/flood-impact-reports', impactIdempotencyPayload($event, [
        'client_uuid' => 'a-new-key',
        'supersedes_id' => $previous->id,
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('supersedes_id');

    expect(FloodImpactReport::count())->toBe(1);
});
