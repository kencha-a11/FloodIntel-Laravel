<?php

use App\Enums\FloodImpactStatus;
use App\Enums\SitrepSection;
use App\Models\Barangay;
use App\Models\FloodEvent;
use App\Models\FloodImpactReport;
use App\Models\SituationalReport;
use App\Models\SituationalReportIssuance;
use App\Models\SituationalReportRun;
use App\Models\SituationalReportSupplement;
use App\Models\User;
use App\Services\SituationalReportAggregator;
use App\Support\ConsolidationWindow;
use App\Support\SituationalReportSnapshot;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use LogicException;

uses(LazilyRefreshDatabase::class);

function sitrepCompletionAt(string $instant): Carbon
{
    return Carbon::parse($instant, 'Asia/Manila');
}

it('includes the latest non-superseded impact filing per barangay, cumulative for the event', function () {
    $barangayA = Barangay::factory()->create(['name' => 'Balucuc', 'code' => 'PH0305402001']);
    $barangayB = Barangay::factory()->create(['name' => 'Colgante', 'code' => 'PH0305402002']);
    $barangayC = Barangay::factory()->create(['name' => 'Aplaya', 'code' => 'PH0305402003']);
    $event = FloodEvent::factory()->create();
    $otherEvent = FloodEvent::factory()->create();
    $staff = User::factory()->staff()->create();

    FloodImpactReport::factory()->for($event, 'event')->for($barangayA)->create([
        'families' => 5, 'individuals' => 20,
        'reported_at' => sitrepCompletionAt('2026-11-02 06:00:00')->utc(),
    ]);
    FloodImpactReport::factory()->for($event, 'event')->for($barangayA)->create([
        'families' => 8, 'individuals' => 32,
        'reported_at' => sitrepCompletionAt('2026-11-02 08:00:00')->utc(),
    ]);
    FloodImpactReport::factory()->for($event, 'event')->for($barangayB)->create([
        'families' => 3, 'individuals' => 12,
        'reported_at' => sitrepCompletionAt('2026-11-02 07:00:00')->utc(),
        'status' => FloodImpactStatus::Superseded,
    ]);
    FloodImpactReport::factory()->for($event, 'event')->for($barangayC)->create([
        'families' => 4, 'individuals' => 16,
        'reported_at' => sitrepCompletionAt('2026-11-02 11:00:00')->utc(),
    ]);
    FloodImpactReport::factory()->for($otherEvent, 'event')->for($barangayB)->create([
        'families' => 99, 'individuals' => 400,
        'reported_at' => sitrepCompletionAt('2026-11-02 07:00:00')->utc(),
    ]);

    $snapshot = app(SituationalReportAggregator::class)->aggregate(
        ConsolidationWindow::current(sitrepCompletionAt('2026-11-02 09:00:00')),
        sitrepCompletionAt('2026-11-02 10:00:00'),
        $event->id,
    );

    $rows = collect($snapshot->barangays)->keyBy('barangay_code');

    expect($rows['PH0305402001']['affected_families'])->toBe(8)
        ->and($rows['PH0305402001']['affected_individuals'])->toBe(32)
        ->and($rows['PH0305402002']['affected_families'])->toBeNull()
        ->and($rows['PH0305402002']['affected_individuals'])->toBeNull()
        ->and($rows['PH0305402003']['affected_families'])->toBeNull()
        ->and($snapshot->stageTwo['available'])->toBeTrue()
        ->and($snapshot->stageTwo['basis'])->toBe(SituationalReportSnapshot::IMPACT_BASIS)
        ->and($snapshot->stageTwo['reason'])->toContain('Cumulative for the event')
        ->and($snapshot->stageTwo['affected_families'])->toBe(8)
        ->and($snapshot->stageTwo['affected_individuals'])->toBe(32);
});

it('reports impact totals as unavailable when the event has no filing yet', function () {
    Barangay::factory()->create(['name' => 'Balucuc', 'code' => 'PH0305402001']);
    $event = FloodEvent::factory()->create();

    $snapshot = app(SituationalReportAggregator::class)->aggregate(
        ConsolidationWindow::current(sitrepCompletionAt('2026-11-02 09:00:00')),
        sitrepCompletionAt('2026-11-02 10:00:00'),
        $event->id,
    );

    expect($snapshot->stageTwo['available'])->toBeTrue()
        ->and($snapshot->stageTwo['affected_families'])->toBeNull()
        ->and($snapshot->stageTwo['affected_individuals'])->toBeNull()
        ->and($snapshot->barangays[0]['affected_families'])->toBeNull();
});

it('freezes supplement drafts into the revision and ignores later edits', function () {
    Barangay::factory()->create(['name' => 'Balucuc', 'code' => 'PH0305402001']);
    $event = FloodEvent::factory()->create(['title' => 'Habagat flooding']);
    $staff = User::factory()->staff()->create();
    $supplement = SituationalReportSupplement::factory()->for($event, 'event')->create([
        'section' => SitrepSection::Weather,
        'payload' => ['summary' => 'Moderate to heavy rain'],
        'created_by_name' => $staff->full_name,
    ]);

    $aggregator = app(SituationalReportAggregator::class);
    $window = ConsolidationWindow::current(sitrepCompletionAt('2026-11-02 09:00:00'));

    $first = $aggregator->prepare($window, $staff, sitrepCompletionAt('2026-11-02 10:00:00'), $event->id);

    expect($first->event_id)->toBe($event->id)
        ->and($first->format_version)->toBe(SituationalReportSnapshot::FORMAT_VERSION)
        ->and($first->content['event']['title'])->toBe('Habagat flooding')
        ->and($first->content['supplements'])->toHaveCount(1)
        ->and($first->content['supplements'][0]['payload']['summary'])->toBe('Moderate to heavy rain')
        ->and($first->content['adaptation'])->toContain('Aggregate-only');

    $supplement->update(['payload' => ['summary' => 'Rain has eased']]);

    $stored = SituationalReport::query()->findOrFail($first->id);

    expect($stored->content['supplements'][0]['payload']['summary'])->toBe('Moderate to heavy rain');

    $second = $aggregator->prepare($window, $staff, sitrepCompletionAt('2026-11-02 10:30:00'), $event->id);

    expect($second->revision)->toBe(2)
        ->and($second->content['supplements'][0]['payload']['summary'])->toBe('Rain has eased');
});

it('keeps a revision without an event on the stage-1 contract', function () {
    Barangay::factory()->create(['name' => 'Balucuc', 'code' => 'PH0305402001']);
    $staff = User::factory()->staff()->create();

    $report = app(SituationalReportAggregator::class)->prepare(
        ConsolidationWindow::current(sitrepCompletionAt('2026-11-02 09:00:00')),
        $staff,
        sitrepCompletionAt('2026-11-02 10:00:00'),
    );

    expect($report->event_id)->toBeNull()
        ->and($report->content)->toBeNull()
        ->and($report->stage_two['available'])->toBeFalse();
});

it('refuses to edit or delete issuance records and run outcomes', function () {
    $event = FloodEvent::factory()->create();
    $staff = User::factory()->staff()->create();
    $officer = User::factory()->officer()->create();
    Barangay::factory()->create(['name' => 'Balucuc', 'code' => 'PH0305402001']);

    $report = app(SituationalReportAggregator::class)->prepare(
        ConsolidationWindow::current(sitrepCompletionAt('2026-11-02 09:00:00')),
        $staff,
        sitrepCompletionAt('2026-11-02 10:00:00'),
        $event->id,
    );

    $issuance = SituationalReportIssuance::query()->create([
        'situational_report_id' => $report->id,
        'event_id' => $event->id,
        'report_number' => 1,
        'issued_by_user_id' => $officer->id,
        'issued_by_name' => $officer->full_name,
        'issued_by_role' => $officer->role->value,
        'office' => config('sitrep.office'),
        'issued_at' => sitrepCompletionAt('2026-11-02 10:05:00')->utc(),
    ]);
    $run = SituationalReportRun::query()->create([
        'cutoff_at' => sitrepCompletionAt('2026-11-02 15:00:00')->utc(),
        'window_starts_at' => sitrepCompletionAt('2026-11-02 04:00:00')->utc(),
        'state' => 'prepared',
        'situational_report_id' => $report->id,
        'triggered_by' => 'manual',
        'actor_user_id' => $staff->id,
        'actor_name' => $staff->full_name,
    ]);

    expect(fn () => $issuance->update(['report_number' => 2]))->toThrow(LogicException::class);
    expect(fn () => $issuance->delete())->toThrow(LogicException::class);
    expect(fn () => $run->update(['state' => 'failed']))->toThrow(LogicException::class);
    expect(fn () => $run->delete())->toThrow(LogicException::class);
});
