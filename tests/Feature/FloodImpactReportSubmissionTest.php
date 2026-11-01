<?php

use App\Models\Barangay;
use App\Models\FloodEvent;
use App\Models\FloodImpactReport;
use App\Models\FloodReport;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('lists only open events, newest first', function () {
    $captain = User::factory()->captain()->create();
    $older = FloodEvent::factory()->create(['starts_at' => now()->subDays(2)]);
    $newer = FloodEvent::factory()->create(['starts_at' => now()->subHour()]);
    FloodEvent::factory()->closed()->create();

    $this->withToken($captain->createToken('t')->plainTextToken)
        ->getJson('/api/flood-events')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $newer->id)
        ->assertJsonPath('data.1.id', $older->id)
        ->assertJsonPath('data.0.is_closed', false);
});

it('requires authentication to list events', function () {
    FloodEvent::factory()->create();

    $this->getJson('/api/flood-events')->assertUnauthorized();
});

it('files an impact report against the captain barangay', function () {
    $barangay = Barangay::factory()->create();
    $captain = User::factory()->captain()->forBarangay($barangay)->create();
    $event = FloodEvent::factory()->create();

    $this->withToken($captain->createToken('t')->plainTextToken)
        ->postJson('/api/flood-impact-reports', [
            'event_id' => $event->id,
            'families' => 12,
            'individuals' => 48,
            'remarks' => 'Water reached the second step of the barangay hall.',
        ])
        ->assertCreated()
        ->assertJsonPath('data.barangay_id', $barangay->id)
        ->assertJsonPath('data.status', 'submitted');

    expect(FloodImpactReport::query()->count())->toBe(1);
});

it('refuses an impact report for a closed event', function () {
    $captain = User::factory()->captain()->forBarangay(Barangay::factory()->create())->create();
    $event = FloodEvent::factory()->closed()->create();

    $this->withToken($captain->createToken('t')->plainTextToken)
        ->postJson('/api/flood-impact-reports', [
            'event_id' => $event->id,
            'families' => 1,
            'individuals' => 1,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('event_id');

    expect(FloodImpactReport::query()->count())->toBe(0);
});

it('refuses more families than individuals', function () {
    $captain = User::factory()->captain()->forBarangay(Barangay::factory()->create())->create();
    $event = FloodEvent::factory()->create();

    $this->withToken($captain->createToken('t')->plainTextToken)
        ->postJson('/api/flood-impact-reports', [
            'event_id' => $event->id,
            'families' => 5,
            'individuals' => 4,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('individuals');
});

it('supersedes the previous report when correcting it', function () {
    $barangay = Barangay::factory()->create();
    $captain = User::factory()->captain()->forBarangay($barangay)->create();
    $event = FloodEvent::factory()->create();
    $previous = FloodImpactReport::factory()->for($captain)->create([
        'barangay_id' => $barangay->id,
        'event_id' => $event->id,
        'families' => 12,
        'individuals' => 48,
    ]);

    $token = $captain->createToken('t')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/flood-impact-reports', [
            'event_id' => $event->id,
            'families' => 20,
            'individuals' => 80,
            'supersedes_id' => $previous->id,
        ])
        ->assertCreated()
        ->assertJsonPath('data.supersedes_id', $previous->id)
        ->assertJsonPath('data.status', 'submitted');

    expect($previous->refresh()->status->value)->toBe('superseded');
});

it('refuses to correct a report from another barangay', function () {
    $captain = User::factory()->captain()->forBarangay(Barangay::factory()->create())->create();
    $event = FloodEvent::factory()->create();
    $foreign = FloodImpactReport::factory()->create(['event_id' => $event->id]);

    $this->withToken($captain->createToken('t')->plainTextToken)
        ->postJson('/api/flood-impact-reports', [
            'event_id' => $event->id,
            'families' => 3,
            'individuals' => 9,
            'supersedes_id' => $foreign->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('supersedes_id');

    expect($foreign->refresh()->status->value)->toBe('submitted');
});

it('refuses to correct a report filed against another event', function () {
    $barangay = Barangay::factory()->create();
    $captain = User::factory()->captain()->forBarangay($barangay)->create();
    $event = FloodEvent::factory()->create();
    $otherEvent = FloodEvent::factory()->create();
    $previous = FloodImpactReport::factory()->for($captain)->create([
        'barangay_id' => $barangay->id,
        'event_id' => $otherEvent->id,
    ]);

    $this->withToken($captain->createToken('t')->plainTextToken)
        ->postJson('/api/flood-impact-reports', [
            'event_id' => $event->id,
            'families' => 3,
            'individuals' => 9,
            'supersedes_id' => $previous->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('supersedes_id');
});

it('refuses to correct a report that is already superseded', function () {
    $barangay = Barangay::factory()->create();
    $captain = User::factory()->captain()->forBarangay($barangay)->create();
    $event = FloodEvent::factory()->create();
    $previous = FloodImpactReport::factory()->for($captain)->create([
        'barangay_id' => $barangay->id,
        'event_id' => $event->id,
        'status' => 'superseded',
    ]);

    $this->withToken($captain->createToken('t')->plainTextToken)
        ->postJson('/api/flood-impact-reports', [
            'event_id' => $event->id,
            'families' => 3,
            'individuals' => 9,
            'supersedes_id' => $previous->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('supersedes_id');

    expect(FloodImpactReport::query()->count())->toBe(1);
});

it('scopes the status list to the captain barangay', function () {
    $ownBarangay = Barangay::factory()->create();
    $otherBarangay = Barangay::factory()->create();
    $captain = User::factory()->captain()->forBarangay($ownBarangay)->create();
    $event = FloodEvent::factory()->create();

    FloodImpactReport::factory()->create([
        'barangay_id' => $ownBarangay->id,
        'event_id' => $event->id,
    ]);
    FloodImpactReport::factory()->create([
        'barangay_id' => $otherBarangay->id,
        'event_id' => $event->id,
    ]);

    $this->withToken($captain->createToken('t')->plainTextToken)
        ->getJson('/api/flood-impact-reports')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.barangay_id', $ownBarangay->id);
});

it('lets an officer list every barangay', function () {
    $officer = User::factory()->officer()->create();
    $event = FloodEvent::factory()->create();

    FloodImpactReport::factory()->create(['event_id' => $event->id]);
    FloodImpactReport::factory()->create(['event_id' => $event->id]);

    $this->withToken($officer->createToken('t')->plainTextToken)
        ->getJson('/api/flood-impact-reports')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('stores related level reports from the captain barangay', function () {
    $barangay = Barangay::factory()->create();
    $captain = User::factory()->captain()->forBarangay($barangay)->create();
    $event = FloodEvent::factory()->create();
    $first = FloodReport::factory()->create(['barangay_id' => $barangay->id]);
    $second = FloodReport::factory()->create(['barangay_id' => $barangay->id]);

    $this->withToken($captain->createToken('t')->plainTextToken)
        ->postJson('/api/flood-impact-reports', [
            'event_id' => $event->id,
            'families' => 4,
            'individuals' => 12,
            'related_level_report_ids' => [$first->id, $second->id],
        ])
        ->assertCreated()
        ->assertJsonPath('data.related_level_report_ids', [$first->id, $second->id]);
});

it('refuses a related level report from another barangay', function () {
    $barangay = Barangay::factory()->create();
    $captain = User::factory()->captain()->forBarangay($barangay)->create();
    $event = FloodEvent::factory()->create();
    $foreign = FloodReport::factory()->create([
        'barangay_id' => Barangay::factory()->create()->id,
    ]);

    $this->withToken($captain->createToken('t')->plainTextToken)
        ->postJson('/api/flood-impact-reports', [
            'event_id' => $event->id,
            'families' => 4,
            'individuals' => 12,
            'related_level_report_ids' => [$foreign->id],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('related_level_report_ids.0');

    expect(FloodImpactReport::query()->count())->toBe(0);
});

it('refuses a related level report that does not exist', function () {
    $captain = User::factory()->captain()->forBarangay(Barangay::factory()->create())->create();
    $event = FloodEvent::factory()->create();

    $this->withToken($captain->createToken('t')->plainTextToken)
        ->postJson('/api/flood-impact-reports', [
            'event_id' => $event->id,
            'families' => 1,
            'individuals' => 1,
            'related_level_report_ids' => [999999],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('related_level_report_ids.0');
});

it('refuses the same related level report twice', function () {
    $barangay = Barangay::factory()->create();
    $captain = User::factory()->captain()->forBarangay($barangay)->create();
    $event = FloodEvent::factory()->create();
    $report = FloodReport::factory()->create(['barangay_id' => $barangay->id]);

    $this->withToken($captain->createToken('t')->plainTextToken)
        ->postJson('/api/flood-impact-reports', [
            'event_id' => $event->id,
            'families' => 2,
            'individuals' => 6,
            'related_level_report_ids' => [$report->id, $report->id],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('related_level_report_ids.1');
});

it('caps the related level report list at fifty', function () {
    $barangay = Barangay::factory()->create();
    $captain = User::factory()->captain()->forBarangay($barangay)->create();
    $event = FloodEvent::factory()->create();
    $ids = FloodReport::factory()
        ->count(51)
        ->create(['barangay_id' => $barangay->id])
        ->pluck('id')
        ->all();

    $this->withToken($captain->createToken('t')->plainTextToken)
        ->postJson('/api/flood-impact-reports', [
            'event_id' => $event->id,
            'families' => 2,
            'individuals' => 6,
            'related_level_report_ids' => $ids,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('related_level_report_ids');
});
