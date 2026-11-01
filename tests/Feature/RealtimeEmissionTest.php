<?php

use App\Enums\FloodReportStatus;
use App\Models\FloodReport;
use App\Models\User;
use App\Realtime\ReportBroadcast;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response(['display_name' => 'Mabini Street, Apalit']),
    ]);
    Storage::fake('public');
});

function realtimeSubmission(): array
{
    return [
        'flood_level' => 'knee',
        'latitude' => 14.9501,
        'longitude' => 120.7501,
        'gps_accuracy' => 12.5,
        'photo' => testPhoto(),
        'notes' => 'Water rising near the school.',
        'reported_at' => now()->subMinutes(5)->toIso8601String(),
    ];
}

it('hands the created report to the publisher after the submission commits', function () {
    $tanod = User::factory()->tanod()->create();

    $broadcast = Mockery::mock(ReportBroadcast::class);
    $broadcast->shouldReceive('created')
        ->once()
        ->with(Mockery::on(fn (FloodReport $report): bool => $report->barangay_id === $tanod->barangay_id
            && $report->status === FloodReportStatus::Submitted));
    $this->app->instance(ReportBroadcast::class, $broadcast);

    $this->withToken($tanod->createToken('t')->plainTextToken)
        ->post('/api/flood-reports', realtimeSubmission())
        ->assertCreated();
});

it('hands the rejected report to the publisher after the rejection commits', function () {
    $officer = User::factory()->officer()->create();
    $report = FloodReport::factory()->create();

    $broadcast = Mockery::mock(ReportBroadcast::class);
    $broadcast->shouldReceive('statusChanged')
        ->once()
        ->with(Mockery::on(fn (FloodReport $rejected): bool => $rejected->id === $report->id
            && $rejected->status === FloodReportStatus::Rejected));
    $this->app->instance(ReportBroadcast::class, $broadcast);

    $this->withToken($officer->createToken('t')->plainTextToken)
        ->postJson("/api/flood-reports/{$report->id}/reject", [
            'rejection_reason' => 'The photo is unreadable.',
        ])
        ->assertOk();
});

it('does not publish when the submission is refused before it is written', function () {
    $tanod = User::factory()->tanod()->create();

    $broadcast = Mockery::mock(ReportBroadcast::class);
    $broadcast->shouldNotReceive('created');
    $this->app->instance(ReportBroadcast::class, $broadcast);

    $payload = realtimeSubmission();
    $payload['latitude'] = 200;

    $this->withToken($tanod->createToken('t')->plainTextToken)
        ->post('/api/flood-reports', $payload)
        ->assertUnprocessable();
});
