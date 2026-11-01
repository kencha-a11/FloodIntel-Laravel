<?php

use App\Enums\FloodReportStatus;
use App\Enums\UserAbility;
use App\Models\FloodReport;
use App\Models\User;
use App\Services\FloodReportTransitions;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
});

it('admits only validated reports through the consumption scope', function () {
    $staff = User::factory()->staff()->create();
    $officer = User::factory()->officer()->create();
    $transitions = app(FloodReportTransitions::class);

    $submitted = FloodReport::factory()->create();

    $validated = FloodReport::factory()->create();
    $transitions->validate($validated, $staff);

    $returned = FloodReport::factory()->create();
    $transitions->return($returned, $staff, 'Photo is too blurry to confirm the level.');

    $rejected = FloodReport::factory()->create();
    $transitions->reject($rejected, $officer, 'Not a flood report.');

    expect(FloodReport::query()->validated()->pluck('id')->all())
        ->toBe([$validated->id])
        ->and(FloodReport::query()->count())->toBe(4)
        ->and($submitted->fresh()->status)->toBe(FloodReportStatus::Submitted)
        ->and($returned->fresh()->status)->toBe(FloodReportStatus::Returned)
        ->and($rejected->fresh()->status)->toBe(FloodReportStatus::Rejected);
});

it('treats the queue status filter as a filter, not as the FR-24 enforcement', function () {
    $captain = User::factory()->captain()->create();
    $staff = User::factory()->staff()->create();
    $tanod = User::factory()->tanod()->forBarangay($captain->barangay)->create();
    $transitions = app(FloodReportTransitions::class);

    $ownValidated = FloodReport::factory()
        ->for($tanod, 'user')
        ->for($captain->barangay, 'barangay')
        ->create();
    $transitions->validate($ownValidated, $staff);

    $ownReturned = FloodReport::factory()
        ->for($tanod, 'user')
        ->for($captain->barangay, 'barangay')
        ->create();
    $transitions->return($ownReturned, $staff, 'Landmark does not match the coordinates.');

    $otherValidated = FloodReport::factory()->create();
    $transitions->validate($otherValidated, $staff);

    $this->withToken($captain->createToken('t')->plainTextToken)
        ->getJson('/api/flood-reports?status=validated')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $ownValidated->id);

    $this->withToken($captain->createToken('t')->plainTextToken)
        ->getJson('/api/flood-reports')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    expect($captain->hasAbility(UserAbility::ViewValidatedReports))->toBeFalse();
});

it('does not turn the review queue into a rescue leader consumption path', function () {
    $leader = User::factory()->rescueLeader()->create();
    $staff = User::factory()->staff()->create();

    $validated = FloodReport::factory()->create();
    app(FloodReportTransitions::class)->validate($validated, $staff);

    expect($leader->hasAbility(UserAbility::ViewValidatedReports))->toBeTrue();

    $this->withToken($leader->createToken('t')->plainTextToken)
        ->getJson('/api/flood-reports?status=validated')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});
