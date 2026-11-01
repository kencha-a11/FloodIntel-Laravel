<?php

use App\Enums\AlertLevel;
use App\Enums\FloodReportStatus;
use App\Models\Barangay;
use App\Models\FloodReport;
use App\Models\User;
use App\Services\FloodReportTransitions;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
});

function feedReport(?Barangay $barangay = null, array $attributes = []): FloodReport
{
    $barangay ??= Barangay::factory()->create();
    $reporter = User::factory()->tanod()->forBarangay($barangay)->create();

    return FloodReport::factory()
        ->for($reporter, 'user')
        ->for($barangay, 'barangay')
        ->create($attributes);
}

it('returns only validated reports, never a client-supplied status', function () {
    $staff = User::factory()->staff()->create();
    $transitions = app(FloodReportTransitions::class);

    $validated = feedReport();
    $transitions->validate($validated, $staff);

    feedReport();

    $returned = feedReport();
    $transitions->return($returned, $staff, 'Landmark does not match the coordinates.');

    $rejected = feedReport();
    $transitions->reject($rejected, User::factory()->officer()->create(), 'Not a flood report.');

    $this->withToken($staff->createToken('t')->plainTextToken)
        ->getJson('/api/flood-reports/validated-feed?status=submitted')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $validated->id)
        ->assertJsonPath('data.0.status', FloodReportStatus::Validated->value)
        ->assertJsonPath('meta.truncated', false);
});

it('denies guests and field roles', function () {
    $this->getJson('/api/flood-reports/validated-feed')->assertUnauthorized();

    foreach ([User::factory()->tanod()->create(), User::factory()->captain()->create()] as $fieldUser) {
        $this->withToken($fieldUser->createToken('t')->plainTextToken)
            ->getJson('/api/flood-reports/validated-feed')
            ->assertForbidden();
    }
});

it('admits the command center and rescue leaders', function (string $state) {
    $user = User::factory()->{$state}()->create();

    $this->withToken($user->createToken('t')->plainTextToken)
        ->getJson('/api/flood-reports/validated-feed')
        ->assertOk();
})->with(['officer', 'staff', 'rescueLeader']);

it('filters by bounding box and barangay', function () {
    $staff = User::factory()->staff()->create();
    $transitions = app(FloodReportTransitions::class);
    $sanJuan = Barangay::factory()->create();
    $sanVicente = Barangay::factory()->create();

    $inside = feedReport($sanJuan, ['latitude' => 14.9500, 'longitude' => 120.7600]);
    $outsideBox = feedReport($sanJuan, ['latitude' => 15.1000, 'longitude' => 120.9000]);
    $otherBarangay = feedReport($sanVicente, ['latitude' => 14.9510, 'longitude' => 120.7610]);

    foreach ([$inside, $outsideBox, $otherBarangay] as $report) {
        $transitions->validate($report, $staff);
    }

    $this->withToken($staff->createToken('t')->plainTextToken)
        ->getJson('/api/flood-reports/validated-feed?min_lat=14.94&max_lat=14.96&min_lng=120.75&max_lng=120.77')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->withToken($staff->createToken('t')->plainTextToken)
        ->getJson("/api/flood-reports/validated-feed?min_lat=14.94&max_lat=14.96&min_lng=120.75&max_lng=120.77&barangay_id={$sanVicente->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $otherBarangay->id);
});

it('filters by alert tier and observation window', function () {
    $staff = User::factory()->staff()->create();
    $transitions = app(FloodReportTransitions::class);
    $barangay = Barangay::factory()->create();

    $oldRed = feedReport($barangay, [
        'alert_level' => AlertLevel::RedAlert,
        'reported_at' => now()->subDays(3),
    ]);
    $recentRed = feedReport($barangay, [
        'alert_level' => AlertLevel::RedAlert,
        'reported_at' => now()->subHour(),
    ]);
    $recentGetReady = feedReport($barangay, [
        'alert_level' => AlertLevel::GetReady,
        'reported_at' => now()->subHour(),
    ]);
    $recentNoTier = feedReport($barangay, ['reported_at' => now()->subHour()]);

    foreach ([$oldRed, $recentRed, $recentGetReady, $recentNoTier] as $report) {
        $transitions->validate($report, $staff);
    }

    $this->withToken($staff->createToken('t')->plainTextToken)
        ->getJson('/api/flood-reports/validated-feed?alert_level=red_alert')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->withToken($staff->createToken('t')->plainTextToken)
        ->getJson('/api/flood-reports/validated-feed?from='.now()->subDay()->toDateString())
        ->assertOk()
        ->assertJsonCount(3, 'data');

    $this->withToken($staff->createToken('t')->plainTextToken)
        ->getJson('/api/flood-reports/validated-feed?from='.now()->subDay()->toDateString().'&alert_level=get_ready')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $recentGetReady->id);
});

it('states truncation instead of silently under-reporting', function () {
    $staff = User::factory()->staff()->create();
    $transitions = app(FloodReportTransitions::class);
    $barangay = Barangay::factory()->create();

    $older = feedReport($barangay, ['reported_at' => now()->subMinutes(10)]);
    $newer = feedReport($barangay, ['reported_at' => now()->subMinutes(5)]);

    $transitions->validate($older, $staff);
    $transitions->validate($newer, $staff);

    $this->withToken($staff->createToken('t')->plainTextToken)
        ->getJson('/api/flood-reports/validated-feed?limit=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $newer->id)
        ->assertJsonPath('meta.limit', 1)
        ->assertJsonPath('meta.count', 1)
        ->assertJsonPath('meta.truncated', true);
});

it('shapes the payload for map rendering', function () {
    $staff = User::factory()->staff()->create();
    $barangay = Barangay::factory()->create();
    $report = feedReport($barangay, [
        'latitude' => 14.9500,
        'longitude' => 120.7600,
        'gps_accuracy' => 12.5,
        'alert_level' => AlertLevel::RedAlert,
        'depth_cm' => 85,
        'address' => 'Apalit, Pampanga',
        'reported_at' => now()->subMinutes(20),
    ]);

    app(FloodReportTransitions::class)->validate($report, $staff);

    $this->withToken($staff->createToken('t')->plainTextToken)
        ->getJson('/api/flood-reports/validated-feed')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonStructure([
            'data' => [[
                'id', 'latitude', 'longitude', 'gps_accuracy',
                'barangay' => ['id', 'name'],
                'alert_level', 'alert_level_label', 'alert_severity', 'depth_cm',
                'observed_at', 'received_at', 'status', 'status_label',
                'source', 'source_label',
            ]],
            'meta' => ['limit', 'count', 'truncated'],
        ])
        ->assertJsonPath('data.0.latitude', 14.95)
        ->assertJsonPath('data.0.gps_accuracy', 12.5)
        ->assertJsonPath('data.0.barangay.id', $barangay->id)
        ->assertJsonPath('data.0.alert_level', AlertLevel::RedAlert->value)
        ->assertJsonPath('data.0.alert_severity', 3)
        ->assertJsonPath('data.0.depth_cm', 85)
        ->assertJsonPath('data.0.source', 'reported')
        ->assertJsonMissingPath('data.0.user')
        ->assertJsonMissingPath('data.0.photo_url')
        ->assertJsonMissingPath('data.0.address');
});

it('validates the filter parameters', function (string $query) {
    $staff = User::factory()->staff()->create();

    $this->withToken($staff->createToken('t')->plainTextToken)
        ->getJson('/api/flood-reports/validated-feed?'.$query)
        ->assertUnprocessable();
})->with([
    'partial bounding box' => 'min_lat=14.94&max_lat=14.96&min_lng=120.75',
    'inverted latitude range' => 'min_lat=14.96&max_lat=14.94&min_lng=120.75&max_lng=120.77',
    'inverted longitude range' => 'min_lat=14.94&max_lat=14.96&min_lng=120.77&max_lng=120.75',
    'latitude out of range' => 'min_lat=-91&max_lat=14.96&min_lng=120.75&max_lng=120.77',
    'unknown alert tier' => 'alert_level=level_4',
    'inverted time window' => 'from=2026-11-02&to=2026-11-01',
    'limit out of bounds' => 'limit=0',
]);
