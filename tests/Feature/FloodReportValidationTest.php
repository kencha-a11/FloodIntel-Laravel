<?php

use App\Enums\FloodReportStatus;
use App\Models\FloodReport;
use App\Models\FloodReportStatusChange;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
});

function commandCenterReport(): FloodReport
{
    $reporter = User::factory()->tanod()->create();

    return FloodReport::factory()
        ->for($reporter, 'user')
        ->for($reporter->barangay, 'barangay')
        ->create();
}

/**
 * Move a report without going through the service, for illegal-start cases.
 */
function forceReportStatus(FloodReport $report, FloodReportStatus $status): void
{
    DB::table('flood_reports')->where('id', $report->id)->update(['status' => $status->value]);
}

it('lets staff validate a submitted report', function () {
    Storage::fake('public');

    $staff = User::factory()->staff()->create();
    $report = commandCenterReport();

    $this->withToken($staff->createToken('t')->plainTextToken)
        ->postJson("/api/flood-reports/{$report->id}/validate")
        ->assertOk()
        ->assertJsonPath('data.status', FloodReportStatus::Validated->value)
        ->assertJsonPath('data.status_label', 'Validated');

    $change = FloodReportStatusChange::sole();

    expect($report->fresh()->status)->toBe(FloodReportStatus::Validated)
        ->and($change->from_status)->toBe(FloodReportStatus::Submitted)
        ->and($change->to_status)->toBe(FloodReportStatus::Validated)
        ->and($change->user_id)->toBe($staff->id)
        ->and($change->reason)->toBeNull();
});

it('lets an officer validate too', function () {
    Storage::fake('public');

    $officer = User::factory()->officer()->create();
    $report = commandCenterReport();

    $this->withToken($officer->createToken('t')->plainTextToken)
        ->postJson("/api/flood-reports/{$report->id}/validate")
        ->assertOk()
        ->assertJsonPath('data.status', FloodReportStatus::Validated->value);
});

it('lets staff return a report with a reason', function () {
    Storage::fake('public');

    $staff = User::factory()->staff()->create();
    $report = commandCenterReport();

    $this->withToken($staff->createToken('t')->plainTextToken)
        ->postJson("/api/flood-reports/{$report->id}/return", [
            'return_reason' => 'Photo is too blurry to confirm the level.',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', FloodReportStatus::Returned->value)
        ->assertJsonPath('data.status_label', 'Returned');

    expect(FloodReportStatusChange::sole()->reason)
        ->toBe('Photo is too blurry to confirm the level.');
});

it('requires a return reason', function () {
    Storage::fake('public');

    $staff = User::factory()->staff()->create();
    $report = commandCenterReport();

    $this->withToken($staff->createToken('t')->plainTextToken)
        ->postJson("/api/flood-reports/{$report->id}/return", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['return_reason']);

    expect($report->fresh()->status)->toBe(FloodReportStatus::Submitted)
        ->and(FloodReportStatusChange::count())->toBe(0);
});

it('forbids field roles from validating or returning', function (string $state, string $action) {
    Storage::fake('public');

    $user = User::factory()->{$state}()->create();
    $report = commandCenterReport();

    $this->withToken($user->createToken('t')->plainTextToken)
        ->postJson("/api/flood-reports/{$report->id}/{$action}")
        ->assertForbidden();
})->with([
    ['tanod', 'validate'],
    ['tanod', 'return'],
    ['captain', 'validate'],
    ['captain', 'return'],
]);

it('answers 422 when the report cannot reach the requested status', function (string $action, FloodReportStatus $current, array $payload) {
    Storage::fake('public');

    $staff = User::factory()->staff()->create();
    $report = commandCenterReport();
    forceReportStatus($report, $current);

    $this->withToken($staff->createToken('t')->plainTextToken)
        ->postJson("/api/flood-reports/{$report->id}/{$action}", $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);

    expect($report->fresh()->status)->toBe($current)
        ->and(FloodReportStatusChange::count())->toBe(0);
})->with([
    'validate a validated report' => ['validate', FloodReportStatus::Validated, []],
    'validate a rejected report' => ['validate', FloodReportStatus::Rejected, []],
    'return a returned report' => ['return', FloodReportStatus::Returned, ['return_reason' => 'Again.']],
]);

it('shows the transition history on the detail but not the queue', function () {
    Storage::fake('public');

    $staff = User::factory()->staff()->create();
    $report = commandCenterReport();

    $this->withToken($staff->createToken('t')->plainTextToken)
        ->postJson("/api/flood-reports/{$report->id}/validate")
        ->assertOk()
        ->assertJsonCount(1, 'data.status_changes')
        ->assertJsonPath('data.status_changes.0.from_status', FloodReportStatus::Submitted->value)
        ->assertJsonPath('data.status_changes.0.to_status_label', 'Validated');

    $this->withToken($staff->createToken('t')->plainTextToken)
        ->getJson("/api/flood-reports/{$report->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data.status_changes');

    $this->withToken($staff->createToken('t')->plainTextToken)
        ->getJson('/api/flood-reports')
        ->assertOk()
        ->assertJsonMissingPath('data.0.status_changes');
});

it('lets an officer override a staff validation, with two audited rows', function () {
    Storage::fake('public');

    $staff = User::factory()->staff()->create();
    $officer = User::factory()->officer()->create();
    $report = commandCenterReport();

    $this->withToken($staff->createToken('t')->plainTextToken)
        ->postJson("/api/flood-reports/{$report->id}/validate")
        ->assertOk();

    // Sanctum caches the resolved user for the test's application instance.
    $this->app['auth']->forgetGuards();

    $this->withToken($officer->createToken('t')->plainTextToken)
        ->postJson("/api/flood-reports/{$report->id}/override", [
            'to_status' => FloodReportStatus::Returned->value,
            'reason' => 'The measured level does not match the photo.',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', FloodReportStatus::Returned->value)
        ->assertJsonCount(2, 'data.status_changes');

    $changes = FloodReportStatusChange::orderBy('id')->get();

    expect($changes)->toHaveCount(2)
        ->and($changes[0]->user_id)->toBe($staff->id)
        ->and($changes[1]->user_id)->toBe($officer->id)
        ->and($changes[1]->from_status)->toBe(FloodReportStatus::Validated)
        ->and($changes[1]->to_status)->toBe(FloodReportStatus::Returned)
        ->and($changes[1]->reason)->toBe('The measured level does not match the photo.');
});

it('requires an override reason and a reachable target', function (array $payload, string $field) {
    Storage::fake('public');

    $officer = User::factory()->officer()->create();
    $report = commandCenterReport();
    forceReportStatus($report, FloodReportStatus::Validated);

    $this->withToken($officer->createToken('t')->plainTextToken)
        ->postJson("/api/flood-reports/{$report->id}/override", $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    expect($report->fresh()->status)->toBe(FloodReportStatus::Validated)
        ->and(FloodReportStatusChange::count())->toBe(0);
})->with([
    'no reason' => [['to_status' => 'returned'], 'reason'],
    'submitted is not an override target' => [['to_status' => 'submitted', 'reason' => 'No.'], 'to_status'],
]);

it('refuses an override that the transition table does not allow', function () {
    Storage::fake('public');

    $officer = User::factory()->officer()->create();
    $report = commandCenterReport();

    $this->withToken($officer->createToken('t')->plainTextToken)
        ->postJson("/api/flood-reports/{$report->id}/override", [
            'to_status' => FloodReportStatus::Returned->value,
            'reason' => 'Not submitted yet.',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);
});

it('forbids staff from overriding a decision', function () {
    Storage::fake('public');

    $staff = User::factory()->staff()->create();
    $report = commandCenterReport();
    forceReportStatus($report, FloodReportStatus::Validated);

    $this->withToken($staff->createToken('t')->plainTextToken)
        ->postJson("/api/flood-reports/{$report->id}/override", [
            'to_status' => FloodReportStatus::Returned->value,
            'reason' => 'Should not work.',
        ])
        ->assertForbidden();
});
