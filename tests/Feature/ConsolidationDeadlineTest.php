<?php

use App\Enums\FloodReportStatus;
use App\Models\Barangay;
use App\Models\FloodReport;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;

uses(LazilyRefreshDatabase::class);

function deadlineManila(string $instant): Carbon
{
    return Carbon::parse($instant, 'Asia/Manila');
}

function deadlineReport(Barangay $barangay, Carbon $at, FloodReportStatus $status = FloodReportStatus::Submitted, bool $nullReportedAt = false, array $attributes = []): FloodReport
{
    $reporter = User::factory()->tanod()->forBarangay($barangay)->create();
    $utc = $at->copy()->utc();

    return FloodReport::factory()
        ->for($reporter, 'user')
        ->for($barangay, 'barangay')
        ->create([
            'status' => $status,
            'reported_at' => $nullReportedAt ? null : $utc,
            'created_at' => $utc,
            'updated_at' => $utc,
            ...$attributes,
        ]);
}

it('rolls the window at the 4:00 AM cutoff', function () {
    $officer = User::factory()->officer()->create();
    $token = $officer->createToken('t')->plainTextToken;

    $this->travelTo(deadlineManila('2026-11-02 03:59:00'));

    $this->withToken($token)
        ->getJson('/api/flood-reports/deadlines')
        ->assertOk()
        ->assertJsonPath('data.window.starts_at', '2026-11-01T15:00:00+08:00')
        ->assertJsonPath('data.window.next_cutoff_at', '2026-11-02T04:00:00+08:00')
        ->assertJsonPath('data.now', '2026-11-02T03:59:00+08:00')
        ->assertJsonPath('data.window.label', '3:00 PM – 4:00 AM');

    $this->travelTo(deadlineManila('2026-11-02 04:01:00'));

    $this->withToken($token)
        ->getJson('/api/flood-reports/deadlines')
        ->assertOk()
        ->assertJsonPath('data.window.starts_at', '2026-11-02T04:00:00+08:00')
        ->assertJsonPath('data.window.next_cutoff_at', '2026-11-02T15:00:00+08:00')
        ->assertJsonPath('data.window.label', '4:00 AM – 3:00 PM');
});

it('counts only submitted reports whose window instant falls in the current window', function () {
    $officer = User::factory()->officer()->create();
    $barangay = Barangay::factory()->create(['name' => 'San Juan']);

    $this->travelTo(deadlineManila('2026-11-02 10:00:00'));

    $current = deadlineReport($barangay, deadlineManila('2026-11-02 06:30:00'));
    deadlineReport($barangay, deadlineManila('2026-11-02 06:45:00'), FloodReportStatus::Validated);
    deadlineReport($barangay, deadlineManila('2026-11-02 04:05:00'), FloodReportStatus::Returned);
    deadlineReport($barangay, deadlineManila('2026-11-02 04:10:00'), FloodReportStatus::Rejected);
    deadlineReport($barangay, deadlineManila('2026-11-01 20:00:00'));
    deadlineReport($barangay, deadlineManila('2026-11-02 15:05:00'));

    $this->withToken($officer->createToken('t')->plainTextToken)
        ->getJson('/api/flood-reports/deadlines')
        ->assertOk()
        ->assertJsonPath('data.pending.total', 1)
        ->assertJsonPath('data.pending.basis', 'reported_at')
        ->assertJsonPath('data.pending.by_barangay.0.barangay_id', $barangay->id)
        ->assertJsonPath('data.pending.by_barangay.0.barangay', 'San Juan')
        ->assertJsonPath('data.pending.by_barangay.0.pending', 1);
});

it('falls back to the receipt time and states that basis', function () {
    $officer = User::factory()->officer()->create();
    $barangay = Barangay::factory()->create();

    $this->travelTo(deadlineManila('2026-11-02 10:00:00'));

    deadlineReport($barangay, deadlineManila('2026-11-02 07:00:00'));
    deadlineReport($barangay, deadlineManila('2026-11-02 07:15:00'), nullReportedAt: true);

    $this->withToken($officer->createToken('t')->plainTextToken)
        ->getJson('/api/flood-reports/deadlines')
        ->assertOk()
        ->assertJsonPath('data.pending.total', 2)
        ->assertJsonPath('data.pending.basis', 'reported_at_with_created_at_fallback');
});

it('lists every barangay with zero counts and scopes the command center', function () {
    $officer = User::factory()->officer()->create();
    $staff = User::factory()->staff()->create();
    $barangayA = Barangay::factory()->create(['name' => 'Aplaya']);
    $barangayB = Barangay::factory()->create(['name' => 'Balite']);

    $this->travelTo(deadlineManila('2026-11-02 10:00:00'));

    deadlineReport($barangayB, deadlineManila('2026-11-02 08:00:00'));

    $this->getJson('/api/flood-reports/deadlines')->assertUnauthorized();

    foreach ([$officer, $staff] as $commandCenter) {
        $this->withToken($commandCenter->createToken('t')->plainTextToken)
            ->getJson('/api/flood-reports/deadlines')
            ->assertOk()
            ->assertJsonCount(2, 'data.pending.by_barangay')
            ->assertJsonPath('data.pending.total', 1)
            ->assertJsonPath('data.pending.by_barangay.0.barangay', 'Aplaya')
            ->assertJsonPath('data.pending.by_barangay.0.pending', 0)
            ->assertJsonPath('data.pending.by_barangay.1.barangay', 'Balite')
            ->assertJsonPath('data.pending.by_barangay.1.pending', 1);
    }
});

it('denies field roles', function (string $state) {
    $user = User::factory()->{$state}()->create();

    $this->withToken($user->createToken('t')->plainTextToken)
        ->getJson('/api/flood-reports/deadlines')
        ->assertForbidden();
})->with(['captain', 'tanod', 'rescueLeader']);

it('filters the queue by alert level', function () {
    $staff = User::factory()->staff()->create();
    $barangay = Barangay::factory()->create();

    $this->travelTo(deadlineManila('2026-11-02 10:00:00'));

    $critical = deadlineReport($barangay, deadlineManila('2026-11-02 08:00:00'), attributes: ['alert_level' => 'red_alert']);
    deadlineReport($barangay, deadlineManila('2026-11-02 08:30:00'), attributes: ['alert_level' => 'alert']);

    $this->withToken($staff->createToken('t')->plainTextToken)
        ->getJson('/api/flood-reports?alert_level=red_alert')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $critical->id);
});
