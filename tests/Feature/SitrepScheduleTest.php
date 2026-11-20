<?php

use App\Enums\FloodReportStatus;
use App\Models\Barangay;
use App\Models\FloodEvent;
use App\Models\FloodReport;
use App\Models\SituationalReport;
use App\Models\SituationalReportRun;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

uses(LazilyRefreshDatabase::class);

function sitrepScheduleAt(string $instant): Carbon
{
    return Carbon::parse($instant, 'Asia/Manila');
}

it('prepares the just-closed window at the cutoff and records a prepared run', function () {
    Barangay::factory()->create(['name' => 'Balucuc', 'code' => 'PH0305402001']);
    $event = FloodEvent::factory()->create(['starts_at' => sitrepScheduleAt('2026-11-01 08:00:00')->utc()]);
    $staff = User::factory()->staff()->create();
    config(['sitrep.scheduler_user_id' => $staff->id]);

    $this->travelTo(sitrepScheduleAt('2026-11-02 04:01:00'));

    $this->artisan('sitrep:prepare-cutoff', ['cutoff' => '04:00'])->assertSuccessful();

    $report = SituationalReport::query()->firstOrFail();

    expect($report->cutoff_at->utc()->toIso8601String())->toBe('2026-11-01T20:00:00+00:00')
        ->and($report->window_starts_at->utc()->toIso8601String())->toBe('2026-11-01T07:00:00+00:00')
        ->and($report->event_id)->toBe($event->id)
        ->and($report->generated_by_user_id)->toBe($staff->id);

    $run = SituationalReportRun::query()->firstOrFail();

    expect($run->state)->toBe('prepared')
        ->and($run->situational_report_id)->toBe($report->id)
        ->and($run->triggered_by)->toBe('schedule')
        ->and($run->actor_user_id)->toBe($staff->id);
});

it('refuses a cutoff that has not passed yet', function () {
    $staff = User::factory()->staff()->create();
    config(['sitrep.scheduler_user_id' => $staff->id]);

    $this->travelTo(sitrepScheduleAt('2026-11-02 03:59:00'));

    $this->artisan('sitrep:prepare-cutoff', ['cutoff' => '04:00'])->assertFailed();

    expect(SituationalReport::query()->count())->toBe(0)
        ->and(SituationalReportRun::query()->count())->toBe(0);
});

it('does not backdate a delayed run and does not duplicate a prepared cutoff', function () {
    Barangay::factory()->create(['name' => 'Balucuc', 'code' => 'PH0305402001']);
    $staff = User::factory()->staff()->create();
    config(['sitrep.scheduler_user_id' => $staff->id]);

    // The 04:00 job fires late, at 04:40: the window still ends at 04:00,
    // and the snapshot instant is the actual run time.
    $this->travelTo(sitrepScheduleAt('2026-11-02 04:40:00'));

    $this->artisan('sitrep:prepare-cutoff', ['cutoff' => '04:00'])->assertSuccessful();

    $report = SituationalReport::query()->firstOrFail();

    expect($report->cutoff_at->utc()->toIso8601String())->toBe('2026-11-01T20:00:00+00:00')
        ->and($report->snapshot_at->utc()->toIso8601String())->toBe('2026-11-01T20:40:00+00:00');

    $this->artisan('sitrep:prepare-cutoff', ['cutoff' => '04:00'])->assertSuccessful();

    expect(SituationalReport::query()->count())->toBe(1)
        ->and(SituationalReportRun::query()->count())->toBe(1);
});

it('records a failed run when no scheduler principal is configured', function () {
    config(['sitrep.scheduler_user_id' => null]);

    $this->travelTo(sitrepScheduleAt('2026-11-02 15:05:00'));

    $this->artisan('sitrep:prepare-cutoff', ['cutoff' => '15:00'])->assertFailed();

    expect(SituationalReport::query()->count())->toBe(0);

    $run = SituationalReportRun::query()->firstOrFail();

    expect($run->state)->toBe('failed')
        ->and($run->reason)->toContain('No scheduler principal')
        ->and($run->actor_user_id)->toBeNull()
        ->and($run->cutoff_at->utc()->toIso8601String())->toBe('2026-11-02T07:00:00+00:00');

    // A retry after configuration prepares without duplicating the failure.
    $staff = User::factory()->staff()->create();
    config(['sitrep.scheduler_user_id' => $staff->id]);

    $this->artisan('sitrep:prepare-cutoff', ['cutoff' => '15:00'])->assertSuccessful();

    expect(SituationalReport::query()->count())->toBe(1)
        ->and(SituationalReportRun::query()->count())->toBe(2)
        ->and(SituationalReportRun::query()->orderByDesc('id')->first()->state)->toBe('prepared');
});

it('records a failed run under the scheduler identity when preparation throws', function () {
    $barangay = Barangay::factory()->create(['name' => 'Balucuc', 'code' => 'PH0305402001']);
    $staff = User::factory()->staff()->create();
    config(['sitrep.scheduler_user_id' => $staff->id]);

    // One filing inside the window guarantees an inclusion insert; the
    // receipt time is the stated observation fallback.
    $observed = sitrepScheduleAt('2026-11-01 18:00:00')->utc();
    FloodReport::factory()
        ->for(User::factory()->tanod()->forBarangay($barangay)->create(), 'user')
        ->for($barangay, 'barangay')
        ->create([
            'status' => FloodReportStatus::Submitted,
            'reported_at' => $observed,
            'created_at' => $observed,
            'updated_at' => $observed,
        ]);

    $this->travelTo(sitrepScheduleAt('2026-11-02 04:02:00'));

    // Force a genuine storage failure inside prepare(): the inclusion table
    // is gone, so the insert throws mid-transaction and the command must
    // record a failed run under the scheduler's identity.
    Schema::drop('situational_report_inclusions');

    $this->artisan('sitrep:prepare-cutoff', ['cutoff' => '04:00'])->assertFailed();

    $run = SituationalReportRun::query()->firstOrFail();

    expect($run->state)->toBe('failed')
        ->and($run->reason)->toContain('situational_report_inclusions')
        ->and($run->actor_user_id)->toBe($staff->id)
        ->and(SituationalReport::query()->count())->toBe(0);
});

it('shows derived pending and not_run states beside recorded attempts', function () {
    $staff = User::factory()->staff()->create();
    $officer = User::factory()->officer()->create();
    config(['sitrep.scheduler_user_id' => $staff->id]);

    $this->travelTo(sitrepScheduleAt('2026-11-02 10:00:00'));

    // The 04:00 cutoff passed with no attempt: not_run. Prepare nothing.
    $response = $this->withToken($officer->createToken('t')->plainTextToken)
        ->getJson('/api/situational-reports/runs/status')
        ->assertOk()
        ->json('data');

    $byLabel = collect($response)->keyBy(fn (array $row): string => Carbon::parse($row['cutoff_at'])->setTimezone('Asia/Manila')->format('Y-m-d H:i'));

    expect($byLabel['2026-11-02 04:00']['state'])->toBe('not_run')
        ->and($byLabel['2026-11-02 15:00']['state'])->toBe('pending');

    $this->artisan('sitrep:prepare-cutoff', ['cutoff' => '04:00', '--trigger' => 'manual'])->assertSuccessful();

    $response = $this->withToken($officer->createToken('t')->plainTextToken)
        ->getJson('/api/situational-reports/runs/status')
        ->assertOk()
        ->json('data');

    $byLabel = collect($response)->keyBy(fn (array $row): string => Carbon::parse($row['cutoff_at'])->setTimezone('Asia/Manila')->format('Y-m-d H:i'));

    expect($byLabel['2026-11-02 04:00']['state'])->toBe('prepared')
        ->and($byLabel['2026-11-02 04:00']['triggered_by'])->toBe('manual')
        ->and($byLabel['2026-11-02 04:00']['situational_report_id'])->toBe(SituationalReport::query()->firstOrFail()->id);
});

it('registers both cutoff jobs in the schedule', function () {
    $events = collect(app(Schedule::class)->events())
        ->map(fn ($event): string => $event->command)
        ->filter(fn (?string $command): bool => $command !== null && str_contains($command, 'sitrep:prepare-cutoff'))
        ->values();

    expect($events)->toHaveCount(2)
        ->and($events->contains(fn (string $command): bool => str_contains($command, '04:00')))->toBeTrue()
        ->and($events->contains(fn (string $command): bool => str_contains($command, '15:00')))->toBeTrue();
});
