<?php

use App\Enums\FloodReportStatus;
use App\Enums\UserAbility;
use App\Models\FloodReport;
use App\Models\FloodReportStatusChange;
use App\Models\User;
use App\Policies\FloodReportPolicy;
use App\Services\FloodReportTransitions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
});

it('refuses a direct status write and leaves the row and the log unchanged', function () {
    $report = FloodReport::factory()->create();

    expect(fn () => $report->update(['status' => FloodReportStatus::Rejected]))
        ->toThrow(LogicException::class, 'FloodReportTransitions');

    expect($report->fresh()->status)->toBe(FloodReportStatus::Submitted)
        ->and(FloodReportStatusChange::count())->toBe(0);
});

it('answers 422 and writes no second row when a rejected report is rejected again', function () {
    Storage::fake('public');

    $officer = User::factory()->officer()->create();
    $reporter = User::factory()->tanod()->create();
    $report = FloodReport::factory()
        ->for($reporter, 'user')
        ->for($reporter->barangay, 'barangay')
        ->create();

    $token = $officer->createToken('t')->plainTextToken;

    $this->withToken($token)
        ->postJson("/api/flood-reports/{$report->id}/reject", ['rejection_reason' => 'Duplicate.'])
        ->assertOk()
        ->assertJsonPath('data.status', FloodReportStatus::Rejected->value);

    $this->withToken($token)
        ->postJson("/api/flood-reports/{$report->id}/reject", ['rejection_reason' => 'Again.'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);

    expect(FloodReportStatusChange::where('flood_report_id', $report->id)->count())->toBe(1);
});

it('refuses to update or delete an audit row', function () {
    $staff = User::factory()->staff()->create();
    $report = FloodReport::factory()->create();

    app(FloodReportTransitions::class)->return($report, $staff, 'Photo is too blurry.');

    $entry = $report->statusChanges()->firstOrFail();

    expect(fn () => $entry->update(['reason' => 'Rewritten.']))->toThrow(LogicException::class);
    expect(fn () => $entry->delete())->toThrow(LogicException::class);

    expect(FloodReportStatusChange::count())->toBe(1)
        ->and(FloodReportStatusChange::firstOrFail()->reason)->toBe('Photo is too blurry.');
});

it('keeps the actor name and role after the actor account is deleted', function () {
    $officer = User::factory()->officer()->create();
    $reporter = User::factory()->tanod()->create();
    $report = FloodReport::factory()
        ->for($reporter, 'user')
        ->for($reporter->barangay, 'barangay')
        ->create();

    app(FloodReportTransitions::class)->reject($report, $officer, 'Photo does not match location.');

    $officer->delete();

    $entry = FloodReportStatusChange::firstOrFail()->fresh();

    expect($entry->user_id)->toBeNull()
        ->and($entry->actor_name)->toBe($officer->full_name)
        ->and($entry->actor_role)->toBe('mdrrmo_officer');
});

it('writes exactly one submitted audit row when a report is stored', function () {
    Storage::fake('public');
    Http::fake();

    $tanod = User::factory()->tanod()->create();

    $this->withToken($tanod->createToken('t')->plainTextToken)
        ->post('/api/flood-reports', [
            'flood_level' => 'knee',
            'latitude' => 14.5995,
            'longitude' => 120.9842,
            'gps_accuracy' => 12.5,
            'photo' => testPhoto(),
            'reported_at' => now()->subMinutes(5)->toIso8601String(),
        ])
        ->assertCreated();

    $entry = FloodReportStatusChange::sole();

    expect($entry->flood_report_id)->toBe(FloodReport::sole()->id)
        ->and($entry->from_status)->toBeNull()
        ->and($entry->to_status)->toBe(FloodReportStatus::Submitted)
        ->and($entry->user_id)->toBe($tanod->id)
        ->and($entry->reason)->toBeNull();
});

it('writes no audit row when a submission is refused', function () {
    Storage::fake('public');
    Http::fake();

    $tanod = User::factory()->tanod()->create();

    $this->withToken($tanod->createToken('t')->plainTextToken)
        ->postJson('/api/flood-reports', ['latitude' => 14.5, 'longitude' => 121.0])
        ->assertUnprocessable();

    expect(FloodReport::count())->toBe(0)
        ->and(FloodReportStatusChange::count())->toBe(0);
});

it('writes neither the status nor the log when a transition is illegal', function () {
    $staff = User::factory()->staff()->create();
    $report = FloodReport::factory()->create();
    $transitions = app(FloodReportTransitions::class);

    $transitions->return($report, $staff, 'Photo is too blurry.');

    expect(fn () => $transitions->validate($report, $staff))
        ->toThrow(ValidationException::class);

    expect($report->fresh()->status)->toBe(FloodReportStatus::Returned)
        ->and(FloodReportStatusChange::count())->toBe(1);
});

it('lets staff validate a submitted report', function () {
    $staff = User::factory()->staff()->create();
    $report = FloodReport::factory()->create();

    app(FloodReportTransitions::class)->validate($report, $staff);

    $entry = $report->statusChanges()->firstOrFail();

    expect($report->fresh()->status)->toBe(FloodReportStatus::Validated)
        ->and($entry->from_status)->toBe(FloodReportStatus::Submitted)
        ->and($entry->to_status)->toBe(FloodReportStatus::Validated)
        ->and($entry->user_id)->toBe($staff->id)
        ->and($entry->reason)->toBeNull();
});

it('gates rejection on the validation ability, not on user management', function () {
    $report = FloodReport::factory()->create();
    $policy = new FloodReportPolicy;

    $managesUsersOnly = Mockery::mock(User::class)
        ->shouldReceive('hasAbility')
        ->andReturnUsing(fn (UserAbility $ability): bool => $ability === UserAbility::ManageUsers)
        ->getMock();

    $validatesOnly = Mockery::mock(User::class)
        ->shouldReceive('hasAbility')
        ->andReturnUsing(fn (UserAbility $ability): bool => $ability === UserAbility::ValidateReport)
        ->getMock();

    expect($policy->reject($managesUsersOnly, $report))->toBeFalse()
        ->and($policy->reject($validatesOnly, $report))->toBeTrue();
});

it('refuses a rejection from an account without the validation ability', function () {
    $tanod = User::factory()->tanod()->create();
    $report = FloodReport::factory()->create();

    expect(fn () => app(FloodReportTransitions::class)->reject($report, $tanod, 'Invalid.'))
        ->toThrow(AuthorizationException::class);

    expect(FloodReportStatusChange::count())->toBe(0);
});

it('lets a validation holder reach the reject service and leaves the officer fence to the route', function () {
    $staff = User::factory()->staff()->create();
    $report = FloodReport::factory()->create();

    app(FloodReportTransitions::class)->reject($report, $staff, 'Invalid.');

    expect($report->fresh()->status)->toBe(FloodReportStatus::Rejected);
});

it('requires a reason for a return', function () {
    $staff = User::factory()->staff()->create();
    $report = FloodReport::factory()->create();

    expect(fn () => app(FloodReportTransitions::class)->return($report, $staff, '   '))
        ->toThrow(ValidationException::class);

    expect($report->fresh()->status)->toBe(FloodReportStatus::Submitted)
        ->and(FloodReportStatusChange::count())->toBe(0);
});

it('records an officer override as a second, reasoned row', function () {
    $staff = User::factory()->staff()->create();
    $officer = User::factory()->officer()->create();
    $report = FloodReport::factory()->create();
    $transitions = app(FloodReportTransitions::class);

    $transitions->validate($report, $staff);
    $transitions->override($report, $officer, FloodReportStatus::Returned, 'Depth reading looks wrong.');

    $entries = $report->statusChanges()->get();

    expect($entries)->toHaveCount(2)
        ->and($entries[1]->from_status)->toBe(FloodReportStatus::Validated)
        ->and($entries[1]->to_status)->toBe(FloodReportStatus::Returned)
        ->and($entries[1]->user_id)->toBe($officer->id)
        ->and($entries[1]->reason)->toBe('Depth reading looks wrong.');
});
