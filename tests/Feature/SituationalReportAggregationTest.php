<?php

use App\Enums\AlertLevel;
use App\Enums\FloodLevel;
use App\Enums\FloodReportStatus;
use App\Models\Barangay;
use App\Models\FloodReport;
use App\Models\SituationalReport;
use App\Models\User;
use App\Services\FloodReportTransitions;
use App\Services\SituationalReportAggregator;
use App\Support\ConsolidationWindow;
use App\Support\SituationalReportSnapshot;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use LogicException;

uses(LazilyRefreshDatabase::class);

function sitrepAt(string $instant): Carbon
{
    return Carbon::parse($instant, 'Asia/Manila');
}

function sitrepReport(Barangay $barangay, Carbon $observedAt, array $attributes = []): FloodReport
{
    $reporter = User::factory()->tanod()->forBarangay($barangay)->create();
    $utc = $observedAt->copy()->utc();

    return FloodReport::factory()
        ->for($reporter, 'user')
        ->for($barangay, 'barangay')
        ->create([
            'status' => FloodReportStatus::Submitted,
            'reported_at' => $utc,
            'created_at' => $utc,
            'updated_at' => $utc,
            ...$attributes,
        ]);
}

function sitrepValidate(FloodReport $report, User $actor, Carbon $at): void
{
    Carbon::setTestNow($at->copy()->utc());

    app(FloodReportTransitions::class)->validate($report, $actor);
}

it('aggregates only validated reports observed inside the window', function () {
    $barangayA = Barangay::factory()->create(['name' => 'Balucuc', 'code' => 'PH0305402001']);
    $barangayB = Barangay::factory()->create(['name' => 'Colgante', 'code' => 'PH0305402002']);
    $barangayC = Barangay::factory()->create(['name' => 'Aplaya', 'code' => 'PH0305402003']);
    $staff = User::factory()->staff()->create();

    $outsideWindow = sitrepReport($barangayA, sitrepAt('2026-11-02 03:00:00'), [
        'alert_level' => AlertLevel::RedAlert,
        'flood_level' => FloodLevel::Chest,
    ]);
    $first = sitrepReport($barangayA, sitrepAt('2026-11-02 06:00:00'), [
        'alert_level' => AlertLevel::GetReady,
        'flood_level' => FloodLevel::Knee,
    ]);
    $second = sitrepReport($barangayA, sitrepAt('2026-11-02 08:00:00'), [
        'alert_level' => AlertLevel::RedAlert,
        'flood_level' => FloodLevel::Waist,
    ]);
    sitrepReport($barangayA, sitrepAt('2026-11-02 07:00:00'));
    sitrepReport($barangayA, sitrepAt('2026-11-02 07:30:00'), ['status' => FloodReportStatus::Returned]);
    sitrepReport($barangayC, sitrepAt('2026-11-02 09:00:00'), ['status' => FloodReportStatus::Rejected]);

    sitrepValidate($outsideWindow, $staff, sitrepAt('2026-11-02 07:00:00'));
    sitrepValidate($first, $staff, sitrepAt('2026-11-02 06:30:00'));
    sitrepValidate($second, $staff, sitrepAt('2026-11-02 08:20:00'));

    $this->travelTo(sitrepAt('2026-11-02 10:00:00'));

    $snapshot = app(SituationalReportAggregator::class)->aggregate(ConsolidationWindow::current());

    expect($snapshot->cutoffLabel)->toBe('4:00 AM – 3:00 PM')
        ->and($snapshot->windowStartsAt->toIso8601String())->toBe('2026-11-01T20:00:00+00:00')
        ->and($snapshot->windowEndsAt->toIso8601String())->toBe('2026-11-02T07:00:00+00:00')
        ->and($snapshot->snapshotAt->toIso8601String())->toBe('2026-11-02T02:00:00+00:00')
        ->and($snapshot->barangays)->toHaveCount(3);

    $rowA = $snapshot->barangays[0];

    expect($rowA['barangay_code'])->toBe('PH0305402001')
        ->and($rowA['barangay'])->toBe('Balucuc')
        ->and($rowA['no_report_received'])->toBeFalse()
        ->and($rowA['reports'])->toBe(4)
        ->and($rowA['included_reports'])->toBe(2)
        ->and($rowA['status_counts'])->toBe(['validated' => 2, 'pending' => 1, 'returned' => 1, 'rejected' => 0])
        ->and($rowA['alert_level'])->toBe('red_alert')
        ->and($rowA['flood_level'])->toBe('waist')
        ->and($rowA['affected_families'])->toBeNull()
        ->and($rowA['affected_individuals'])->toBeNull();

    $rowB = $snapshot->barangays[1];

    expect($rowB['barangay_code'])->toBe('PH0305402002')
        ->and($rowB['no_report_received'])->toBeTrue()
        ->and($rowB['reports'])->toBe(0)
        ->and($rowB['included_reports'])->toBe(0)
        ->and($rowB['alert_level'])->toBeNull();

    $rowC = $snapshot->barangays[2];

    expect($rowC['barangay_code'])->toBe('PH0305402003')
        ->and($rowC['no_report_received'])->toBeFalse()
        ->and($rowC['reports'])->toBe(1)
        ->and($rowC['status_counts']['rejected'])->toBe(1)
        ->and($rowC['included_reports'])->toBe(0);

    expect($snapshot->statusCounts)->toBe([
        'basis' => 'reported_at',
        'validated' => 2,
        'pending' => 1,
        'returned' => 1,
        'rejected' => 1,
    ])->and($snapshot->totals)->toBe([
        'reports' => 5,
        'included_reports' => 2,
        'alert_level' => 'red_alert',
        'flood_level' => 'waist',
    ]);

    expect($snapshot->includedReports)->toHaveCount(2)
        ->and(array_column($snapshot->includedReports, 'flood_report_id'))->toBe([$first->id, $second->id])
        ->and($snapshot->includedReports[0]['observed_at']->toIso8601String())->toBe('2026-11-01T22:00:00+00:00')
        ->and($snapshot->includedReports[0]['validated_at']->toIso8601String())->toBe('2026-11-01T22:30:00+00:00');
});

it('treats the window as half-open at the cutoff', function () {
    $barangay = Barangay::factory()->create(['name' => 'Balucuc', 'code' => 'PH0305402001']);
    $staff = User::factory()->staff()->create();

    $before = sitrepReport($barangay, sitrepAt('2026-11-02 03:59:59'));
    $atStart = sitrepReport($barangay, sitrepAt('2026-11-02 04:00:00'));
    $beforeEnd = sitrepReport($barangay, sitrepAt('2026-11-02 14:59:59'));
    $atEnd = sitrepReport($barangay, sitrepAt('2026-11-02 15:00:00'));

    sitrepValidate($before, $staff, sitrepAt('2026-11-02 04:30:00'));
    sitrepValidate($atStart, $staff, sitrepAt('2026-11-02 04:30:00'));
    sitrepValidate($beforeEnd, $staff, sitrepAt('2026-11-02 15:00:00'));
    sitrepValidate($atEnd, $staff, sitrepAt('2026-11-02 15:10:00'));

    $window = ConsolidationWindow::current(sitrepAt('2026-11-02 10:00:00'));
    $snapshot = app(SituationalReportAggregator::class)->aggregate($window, sitrepAt('2026-11-02 15:30:00'));

    expect(array_column($snapshot->includedReports, 'flood_report_id'))
        ->toBe([$atStart->id, $beforeEnd->id]);
});

it('keeps a stored revision stable when a later validation arrives', function () {
    $barangay = Barangay::factory()->create(['name' => 'Balucuc', 'code' => 'PH0305402001']);
    $staff = User::factory()->staff()->create();
    $report = sitrepReport($barangay, sitrepAt('2026-11-02 06:00:00'));

    $aggregator = app(SituationalReportAggregator::class);
    $window = ConsolidationWindow::current(sitrepAt('2026-11-02 09:00:00'));

    $first = $aggregator->prepare($window, $staff, sitrepAt('2026-11-02 09:00:00'));

    expect($first->revision)->toBe(1)
        ->and($first->predecessor_id)->toBeNull()
        ->and($first->format_version)->toBe(SituationalReportSnapshot::FORMAT_VERSION)
        ->and($first->generated_by_name)->toBe($staff->full_name)
        ->and($first->generated_by_role)->toBe($staff->role->value)
        ->and($first->totals['included_reports'])->toBe(0)
        ->and($first->inclusions)->toHaveCount(0);

    sitrepValidate($report, $staff, sitrepAt('2026-11-02 09:30:00'));

    expect($aggregator->aggregate($window, sitrepAt('2026-11-02 09:00:00'))->totals['included_reports'])->toBe(0)
        ->and($aggregator->aggregate($window, sitrepAt('2026-11-02 09:31:00'))->totals['included_reports'])->toBe(1);

    $second = $aggregator->prepare($window, $staff, sitrepAt('2026-11-02 09:31:00'));

    expect($second->revision)->toBe(2)
        ->and($second->predecessor_id)->toBe($first->id)
        ->and($second->totals['included_reports'])->toBe(1)
        ->and($second->inclusions)->toHaveCount(1)
        ->and($second->inclusions->first()->flood_report_id)->toBe($report->id);

    $storedFirst = SituationalReport::query()->with('inclusions')->findOrFail($first->id);

    expect($storedFirst->totals['included_reports'])->toBe(0)
        ->and($storedFirst->inclusions)->toHaveCount(0)
        ->and($storedFirst->status_counts)->toBe($first->status_counts);
});

it('states latency with the reports excluded for a missing observation time', function () {
    $barangay = Barangay::factory()->create(['name' => 'Balucuc', 'code' => 'PH0305402001']);
    $staff = User::factory()->staff()->create();

    $fast = sitrepReport($barangay, sitrepAt('2026-11-02 06:00:00'), [
        'created_at' => sitrepAt('2026-11-02 06:10:00')->utc(),
        'updated_at' => sitrepAt('2026-11-02 06:10:00')->utc(),
    ]);
    $slow = sitrepReport($barangay, sitrepAt('2026-11-02 07:00:00'), [
        'created_at' => sitrepAt('2026-11-02 07:05:00')->utc(),
        'updated_at' => sitrepAt('2026-11-02 07:05:00')->utc(),
    ]);
    $unknown = sitrepReport($barangay, sitrepAt('2026-11-02 06:30:00'), [
        'reported_at' => null,
        'created_at' => sitrepAt('2026-11-02 06:30:00')->utc(),
        'updated_at' => sitrepAt('2026-11-02 06:30:00')->utc(),
    ]);

    sitrepValidate($fast, $staff, sitrepAt('2026-11-02 06:20:00'));
    sitrepValidate($slow, $staff, sitrepAt('2026-11-02 07:10:00'));
    sitrepValidate($unknown, $staff, sitrepAt('2026-11-02 06:40:00'));

    $this->travelTo(sitrepAt('2026-11-02 10:00:00'));

    $snapshot = app(SituationalReportAggregator::class)->aggregate(ConsolidationWindow::current());

    expect($snapshot->statusCounts['basis'])->toBe('reported_at_with_created_at_fallback')
        ->and($snapshot->statusCounts['validated'])->toBe(3)
        ->and($snapshot->totals['included_reports'])->toBe(2)
        ->and($snapshot->latency)->toBe([
            'samples' => 2,
            'excluded_null_observation' => 1,
            'mean_seconds' => 450.0,
            'median_seconds' => 450.0,
            'max_seconds' => 600,
        ]);
});

it('lists every barangay and never claims zero when nothing was filed', function () {
    Barangay::factory()->create(['name' => 'Balucuc', 'code' => 'PH0305402001']);
    Barangay::factory()->create(['name' => 'Colgante', 'code' => 'PH0305402002']);

    $this->travelTo(sitrepAt('2026-11-02 10:00:00'));

    $snapshot = app(SituationalReportAggregator::class)->aggregate(ConsolidationWindow::current());

    expect(array_column($snapshot->barangays, 'barangay'))->toBe(['Balucuc', 'Colgante'])
        ->and(array_column($snapshot->barangays, 'no_report_received'))->toBe([true, true])
        ->and($snapshot->totals)->toBe([
            'reports' => 0,
            'included_reports' => 0,
            'alert_level' => null,
            'flood_level' => null,
        ])
        ->and($snapshot->latency)->toBe([
            'samples' => 0,
            'excluded_null_observation' => 0,
            'mean_seconds' => null,
            'median_seconds' => null,
            'max_seconds' => null,
        ])
        ->and($snapshot->stageTwo['available'])->toBeFalse()
        ->and($snapshot->stageTwo['affected_families'])->toBeNull()
        ->and($snapshot->stageTwo['affected_individuals'])->toBeNull()
        ->and($snapshot->stageTwo['reason'])->toContain('module 03');
});

it('refuses to edit or delete a stored revision and its provenance', function () {
    $barangay = Barangay::factory()->create(['name' => 'Balucuc', 'code' => 'PH0305402001']);
    $staff = User::factory()->staff()->create();
    $report = sitrepReport($barangay, sitrepAt('2026-11-02 06:00:00'));

    sitrepValidate($report, $staff, sitrepAt('2026-11-02 06:30:00'));
    $this->travelTo(sitrepAt('2026-11-02 10:00:00'));

    $snapshot = app(SituationalReportAggregator::class)->prepare(ConsolidationWindow::current(), $staff);
    $inclusion = $snapshot->inclusions->first();

    expect($inclusion)->not->toBeNull();

    expect(fn () => $snapshot->update(['cutoff_label' => 'Changed']))
        ->toThrow(LogicException::class);
    expect(fn () => $snapshot->delete())
        ->toThrow(LogicException::class);
    expect(fn () => $inclusion->update(['alert_level' => AlertLevel::Alert->value]))
        ->toThrow(LogicException::class);
    expect(fn () => $inclusion->delete())
        ->toThrow(LogicException::class);
});

it('lets staff prepare and refuses every other role', function () {
    $barangay = Barangay::factory()->create(['name' => 'Balucuc', 'code' => 'PH0305402001']);
    $staff = User::factory()->staff()->create();
    $officer = User::factory()->officer()->create();
    $tanod = User::factory()->tanod()->forBarangay($barangay)->create();
    $rescueLeader = User::factory()->rescueLeader()->create();

    $this->travelTo(sitrepAt('2026-11-02 10:00:00'));

    $aggregator = app(SituationalReportAggregator::class);
    $window = ConsolidationWindow::current();

    expect(fn () => $aggregator->prepare($window, $officer))->toThrow(AuthorizationException::class);
    expect(fn () => $aggregator->prepare($window, $tanod))->toThrow(AuthorizationException::class);
    expect(fn () => $aggregator->prepare($window, $rescueLeader))->toThrow(AuthorizationException::class);
    expect($aggregator->prepare($window, $staff))->toBeInstanceOf(SituationalReport::class);

    expect(SituationalReport::query()->count())->toBe(1);
});
