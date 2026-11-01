<?php

use App\Enums\AlertLevel;
use App\Enums\AnomalyFlag;
use App\Enums\FloodLevel;
use App\Enums\FloodReportStatus;
use App\Enums\FloodType;
use App\Enums\UserRole;
use App\Models\Barangay;
use App\Models\FloodReport;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
});

function floodReportPayload(array $overrides = []): array
{
    $payload = array_merge([
        'flood_level' => FloodLevel::Knee->value,
        'latitude' => 14.5995,
        'longitude' => 120.9842,
        'gps_accuracy' => 12.5,
        'photo' => testPhoto(),
        'notes' => 'Water rising near the school.',
        'reported_at' => now()->subMinutes(5)->toIso8601String(),
    ], $overrides);

    // Allow removing a key by setting it to null in overrides
    foreach ($overrides as $key => $value) {
        if ($value === null) {
            unset($payload[$key]);
        }
    }

    return $payload;
}

it('lets a tanod submit a flood report with a photo', function () {
    Storage::fake('public');
    Http::fake();

    $tanod = User::factory()->tanod()->create();
    $barangay = $tanod->barangay;

    $response = $this->withToken($tanod->createToken('t')->plainTextToken)
        ->post('/api/flood-reports', floodReportPayload());

    $response->assertCreated()
        ->assertJsonPath('data.flood_level', FloodLevel::Knee->value)
        ->assertJsonPath('data.flood_level_label', FloodLevel::Knee->label())
        ->assertJsonPath('data.severity', 2)
        ->assertJsonPath('data.status', FloodReportStatus::Submitted->value)
        ->assertJsonPath('data.barangay.id', $barangay->id)
        ->assertJsonPath('data.user.id', $tanod->id);

    $report = FloodReport::firstOrFail();

    expect($report->barangay_id)->toBe($tanod->barangay_id)
        ->and($report->photo_path)->not->toBeEmpty();

    Storage::disk('public')->assertExists($report->photo_path);
});

it('lets a captain submit a flood report', function () {
    Storage::fake('public');
    Http::fake();

    $captain = User::factory()->captain()->create();

    $this->withToken($captain->createToken('t')->plainTextToken)
        ->post('/api/flood-reports', floodReportPayload())
        ->assertCreated();
});

it('fills the address after the report has been stored', function () {
    Storage::fake('public');

    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response([
            'display_name' => '17, Mabini Street, Barangay 5, Tacloban City, Leyte, 6500, Philippines',
            'address' => [
                'house_number' => '17',
                'road' => 'Mabini Street',
                'suburb' => 'Barangay 5',
                'city' => 'Tacloban City',
                'state' => 'Leyte',
                'postcode' => '6500',
                'country' => 'Philippines',
            ],
        ]),
    ]);

    $tanod = User::factory()->tanod()->create();

    $this->withToken($tanod->createToken('t')->plainTextToken)
        ->post('/api/flood-reports', floodReportPayload())
        ->assertCreated()
        ->assertJsonPath('data.address', null);

    expect(FloodReport::firstOrFail()->address)
        ->toBe('17, Mabini Street, Barangay 5, Tacloban City, Leyte, 6500, Philippines');
});

it('does not call the geocoder before the report row exists', function () {
    Storage::fake('public');

    $reportSeenByProvider = null;

    Http::fake(function () use (&$reportSeenByProvider) {
        $reportSeenByProvider = FloodReport::query()->first();

        return Http::response(['display_name' => 'Mabini Street, Tacloban City, Philippines']);
    });

    $tanod = User::factory()->tanod()->create();

    $this->withToken($tanod->createToken('t')->plainTextToken)
        ->post('/api/flood-reports', floodReportPayload())
        ->assertCreated();

    expect($reportSeenByProvider)->not->toBeNull()
        ->and($reportSeenByProvider->address)->toBeNull();
});

it('still submits a report when reverse geocoding fails', function () {
    Storage::fake('public');

    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response([], 503),
    ]);

    $tanod = User::factory()->tanod()->create();

    $this->withToken($tanod->createToken('t')->plainTextToken)
        ->post('/api/flood-reports', floodReportPayload())
        ->assertCreated()
        ->assertJsonPath('data.address', null);

    expect(FloodReport::firstOrFail()->address)->toBeNull();
});

it('reuses the cached address for nearby coordinates', function () {
    Storage::fake('public');

    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response([
            'address' => [
                'road' => 'Mabini Street',
                'city' => 'Tacloban City',
                'country' => 'Philippines',
            ],
        ]),
    ]);

    $tanod = User::factory()->tanod()->create();
    $token = $tanod->createToken('t')->plainTextToken;

    $this->withToken($token)
        ->post('/api/flood-reports', floodReportPayload())
        ->assertCreated();

    $this->withToken($token)
        ->post('/api/flood-reports', floodReportPayload([
            'latitude' => 14.5995001,
            'longitude' => 120.9842001,
        ]))
        ->assertCreated();

    Http::assertSentCount(1);
});

it('requires a photo', function () {
    Storage::fake('public');

    $tanod = User::factory()->tanod()->create();

    $payload = floodReportPayload();
    unset($payload['photo']);

    $this->withToken($tanod->createToken('t')->plainTextToken)
        ->post('/api/flood-reports', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['photo']);
});

it('rejects a photo larger than 5MB', function () {
    Storage::fake('public');

    $tanod = User::factory()->tanod()->create();

    $payload = floodReportPayload([
        'photo' => UploadedFile::fake()->create('big.jpg', 5121, 'image/jpeg'),
    ]);

    $this->withToken($tanod->createToken('t')->plainTextToken)
        ->post('/api/flood-reports', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['photo']);
});

it('stores a rebuilt photo with no metadata and a server-chosen name', function () {
    Storage::fake('public');
    Http::fake();

    $tanod = User::factory()->tanod()->create();
    $app1 = "\xFF\xE1".pack('n', 2 + strlen("Exif\0\0GPSDATA"))."Exif\0\0GPSDATA";
    $path = tempnam(sys_get_temp_dir(), 'flood-photo-');
    file_put_contents($path, "\xFF\xD8".$app1.substr(testJpegBytes(), 2));

    $this->withToken($tanod->createToken('t')->plainTextToken)
        ->post('/api/flood-reports', floodReportPayload([
            'photo' => new UploadedFile($path, 'IMG_0001.jpg', 'image/jpeg', null, true),
        ]))
        ->assertCreated();

    $report = FloodReport::firstOrFail();
    $stored = Storage::disk('public')->get($report->photo_path);

    expect($report->photo_path)->toMatch('#^flood-reports/'.$tanod->id.'/\d{4}-\d{2}/[A-Za-z0-9]{40}\.jpg$#')
        ->and($report->photo_path)->not->toContain('IMG_0001')
        ->and($stored)->not->toContain('Exif');

    $copy = tempnam(sys_get_temp_dir(), 'flood-stored-');
    file_put_contents($copy, $stored);
    $size = getimagesize($copy);
    unlink($copy);

    expect($size[0])->toBe(1)->and($size[1])->toBe(1);
});

it('refuses a photo that carries data after its image end', function () {
    Storage::fake('public');

    $tanod = User::factory()->tanod()->create();
    $path = tempnam(sys_get_temp_dir(), 'flood-photo-');
    file_put_contents($path, testJpegBytes()."<?php echo 'pwned';");

    $this->withToken($tanod->createToken('t')->plainTextToken)
        ->post('/api/flood-reports', floodReportPayload([
            'photo' => new UploadedFile($path, 'flood.jpg', 'image/jpeg', null, true),
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['photo']);

    expect(FloodReport::count())->toBe(0)
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

it('validates the flood level', function () {
    Storage::fake('public');

    $tanod = User::factory()->tanod()->create();

    $this->withToken($tanod->createToken('t')->plainTextToken)
        ->post('/api/flood-reports', floodReportPayload(['flood_level' => 'tsunami']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['flood_level']);
});

it('accepts a new-format payload with all fields', function () {
    Storage::fake('public');
    Http::fake();

    $tanod = User::factory()->tanod()->create();

    $this->withToken($tanod->createToken('t')->plainTextToken)
        ->post('/api/flood-reports', array_merge(floodReportPayload(['flood_level' => null]), [
            'alert_level' => AlertLevel::RedAlert->value,
            'flood_type' => FloodType::RiverOverflow->value,
            'depth_cm' => 150,
            'landmark' => 'Near the school gate.',
        ]))
        ->assertCreated()
        ->assertJsonPath('data.alert_level', AlertLevel::RedAlert->value)
        ->assertJsonPath('data.alert_level_label', AlertLevel::RedAlert->label())
        ->assertJsonPath('data.alert_severity', 3)
        ->assertJsonPath('data.flood_type', FloodType::RiverOverflow->value)
        ->assertJsonPath('data.flood_type_label', FloodType::RiverOverflow->label())
        ->assertJsonPath('data.depth_cm', 150)
        ->assertJsonPath('data.landmark', 'Near the school gate.')
        ->assertJsonPath('data.flood_level', null);

    $report = FloodReport::firstOrFail();
    expect($report->alert_level)->toBe(AlertLevel::RedAlert)
        ->and($report->flood_type)->toBe(FloodType::RiverOverflow)
        ->and($report->depth_cm)->toBe(150)
        ->and($report->landmark)->toBe('Near the school gate.')
        ->and($report->flood_level)->toBeNull();
});

it('accepts a new-format payload without optional depth and landmark', function () {
    Storage::fake('public');
    Http::fake();

    $tanod = User::factory()->tanod()->create();

    $this->withToken($tanod->createToken('t')->plainTextToken)
        ->post('/api/flood-reports', array_merge(floodReportPayload(['flood_level' => null]), [
            'alert_level' => AlertLevel::Alert->value,
            'flood_type' => FloodType::Ponding->value,
        ]))
        ->assertCreated()
        ->assertJsonPath('data.alert_level', AlertLevel::Alert->value)
        ->assertJsonPath('data.flood_type', FloodType::Ponding->value)
        ->assertJsonPath('data.depth_cm', null)
        ->assertJsonPath('data.landmark', null);

    $report = FloodReport::firstOrFail();
    expect($report->depth_cm)->toBeNull()
        ->and($report->landmark)->toBeNull();
});

it('still accepts a legacy five-level payload', function () {
    Storage::fake('public');
    Http::fake();

    $tanod = User::factory()->tanod()->create();

    $this->withToken($tanod->createToken('t')->plainTextToken)
        ->post('/api/flood-reports', floodReportPayload())
        ->assertCreated()
        ->assertJsonPath('data.flood_level', FloodLevel::Knee->value)
        ->assertJsonPath('data.flood_level_label', FloodLevel::Knee->label())
        ->assertJsonPath('data.alert_level', null)
        ->assertJsonPath('data.flood_type', null);

    $report = FloodReport::firstOrFail();
    expect($report->flood_level)->toBe(FloodLevel::Knee)
        ->and($report->alert_level)->toBeNull()
        ->and($report->flood_type)->toBeNull();
});

it('rejects a payload with neither level field', function () {
    Storage::fake('public');

    $tanod = User::factory()->tanod()->create();

    $this->withToken($tanod->createToken('t')->plainTextToken)
        ->post('/api/flood-reports', array_merge(floodReportPayload(['flood_level' => null]), [
            'alert_level' => null,
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['alert_level', 'flood_level']);
});

it('rejects a payload with both level fields', function () {
    Storage::fake('public');

    $tanod = User::factory()->tanod()->create();

    $this->withToken($tanod->createToken('t')->plainTextToken)
        ->post('/api/flood-reports', array_merge(floodReportPayload(), [
            'alert_level' => AlertLevel::Alert->value,
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['alert_level', 'flood_level']);
});

it('rejects a new-format payload without flood type', function () {
    Storage::fake('public');

    $tanod = User::factory()->tanod()->create();

    $this->withToken($tanod->createToken('t')->plainTextToken)
        ->post('/api/flood-reports', array_merge(floodReportPayload(['flood_level' => null]), [
            'alert_level' => AlertLevel::Alert->value,
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['flood_type']);
});

it('rejects invalid alert level', function () {
    Storage::fake('public');

    $tanod = User::factory()->tanod()->create();

    $this->withToken($tanod->createToken('t')->plainTextToken)
        ->post('/api/flood-reports', array_merge(floodReportPayload(['flood_level' => null]), [
            'alert_level' => 'tsunami',
            'flood_type' => FloodType::Ponding->value,
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['alert_level']);
});

it('rejects invalid flood type', function () {
    Storage::fake('public');

    $tanod = User::factory()->tanod()->create();

    $this->withToken($tanod->createToken('t')->plainTextToken)
        ->post('/api/flood-reports', array_merge(floodReportPayload(['flood_level' => null]), [
            'alert_level' => AlertLevel::Alert->value,
            'flood_type' => 'flash_flood',
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['flood_type']);
});

it('rejects negative depth', function () {
    Storage::fake('public');

    $tanod = User::factory()->tanod()->create();

    $this->withToken($tanod->createToken('t')->plainTextToken)
        ->post('/api/flood-reports', array_merge(floodReportPayload(['flood_level' => null]), [
            'alert_level' => AlertLevel::Alert->value,
            'flood_type' => FloodType::Ponding->value,
            'depth_cm' => -5,
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['depth_cm']);
});

it('rejects excessive depth', function () {
    Storage::fake('public');

    $tanod = User::factory()->tanod()->create();

    $this->withToken($tanod->createToken('t')->plainTextToken)
        ->post('/api/flood-reports', array_merge(floodReportPayload(['flood_level' => null]), [
            'alert_level' => AlertLevel::Alert->value,
            'flood_type' => FloodType::Ponding->value,
            'depth_cm' => 1001,
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['depth_cm']);
});

it('rejects landmark over 255 characters', function () {
    Storage::fake('public');

    $tanod = User::factory()->tanod()->create();

    $this->withToken($tanod->createToken('t')->plainTextToken)
        ->post('/api/flood-reports', array_merge(floodReportPayload(['flood_level' => null]), [
            'alert_level' => AlertLevel::Alert->value,
            'flood_type' => FloodType::Ponding->value,
            'landmark' => str_repeat('x', 256),
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['landmark']);
});

it('keeps reported_at unchanged when a report is rejected', function () {
    Storage::fake('public');

    $officer = User::factory()->officer()->create();
    $tanod = User::factory()->tanod()->create();
    $reportedAt = '2026-10-26 10:00:00';
    $createdAt = '2026-10-26 10:00:00';
    $report = FloodReport::factory()->for($tanod, 'user')->for($tanod->barangay, 'barangay')->create([
        'reported_at' => $reportedAt,
        'created_at' => $createdAt,
    ]);

    $this->withToken($officer->createToken('t')->plainTextToken)
        ->postJson("/api/flood-reports/{$report->id}/reject", [
            'rejection_reason' => 'Photo does not match location.',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', FloodReportStatus::Rejected->value);

    expect($report->fresh()->reported_at->format('Y-m-d H:i:s'))->toBe($reportedAt)
        ->and($report->fresh()->created_at->format('Y-m-d H:i:s'))->toBe($createdAt);
});

it('validates latitude and longitude ranges', function () {
    Storage::fake('public');

    $tanod = User::factory()->tanod()->create();

    $this->withToken($tanod->createToken('t')->plainTextToken)
        ->post('/api/flood-reports', floodReportPayload(['latitude' => 200]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['latitude']);

    $this->withToken($tanod->createToken('t')->plainTextToken)
        ->post('/api/flood-reports', floodReportPayload(['longitude' => -300]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['longitude']);
});

it('rejects a future reported_at', function () {
    Storage::fake('public');

    $tanod = User::factory()->tanod()->create();

    $this->withToken($tanod->createToken('t')->plainTextToken)
        ->post('/api/flood-reports', floodReportPayload(['reported_at' => now()->addHour()->toIso8601String()]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['reported_at']);
});

it('forbids non-submitters from creating a report', function (string $role) {
    Storage::fake('public');

    $user = User::factory()->role(UserRole::from($role))->create();

    $this->withToken($user->createToken('t')->plainTextToken)
        ->post('/api/flood-reports', floodReportPayload())
        ->assertForbidden();
})->with([
    'mdrrmo_officer',
    'mdrrmo_staff',
    'rescue_leader',
]);

it('requires authentication and verification', function () {
    Storage::fake('public');

    $this->postJson('/api/flood-reports', floodReportPayload())->assertUnauthorized();

    $tanod = User::factory()->tanod()->unverified()->create();
    $token = $tanod->createToken('t')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/flood-reports', floodReportPayload())
        ->assertForbidden();
});

it('shows a report to its owner', function () {
    Storage::fake('public');

    $tanod = User::factory()->tanod()->create();
    $report = FloodReport::factory()->for($tanod, 'user')->for($tanod->barangay, 'barangay')->create();

    $this->withToken($tanod->createToken('t')->plainTextToken)
        ->getJson("/api/flood-reports/{$report->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $report->id);
});

it('hides a report from a tanod in another barangay', function () {
    Storage::fake('public');

    $tanod = User::factory()->tanod()->create();
    $other = User::factory()->tanod()->create();
    $report = FloodReport::factory()->for($other, 'user')->for($other->barangay, 'barangay')->create();

    $this->withToken($tanod->createToken('t')->plainTextToken)
        ->getJson("/api/flood-reports/{$report->id}")
        ->assertForbidden();
});

it('lists only own reports for a tanod', function () {
    Storage::fake('public');

    $tanod = User::factory()->tanod()->create();
    $other = User::factory()->tanod()->create();

    FloodReport::factory()->for($tanod, 'user')->for($tanod->barangay, 'barangay')->create();
    FloodReport::factory()->for($other, 'user')->for($other->barangay, 'barangay')->create();

    $response = $this->withToken($tanod->createToken('t')->plainTextToken)
        ->getJson('/api/flood-reports');

    $response->assertOk()
        ->assertJsonCount(1, 'data');
});

it('lists all reports for an officer', function () {
    Storage::fake('public');

    $officer = User::factory()->officer()->create();
    $tanod = User::factory()->tanod()->create();

    FloodReport::factory()->count(3)->for($tanod, 'user')->for($tanod->barangay, 'barangay')->create();

    $this->withToken($officer->createToken('t')->plainTextToken)
        ->getJson('/api/flood-reports')
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

it('lets an officer reject a report with a reason', function () {
    Storage::fake('public');

    $officer = User::factory()->officer()->create();
    $tanod = User::factory()->tanod()->create();
    $report = FloodReport::factory()->for($tanod, 'user')->for($tanod->barangay, 'barangay')->create();

    $this->withToken($officer->createToken('t')->plainTextToken)
        ->postJson("/api/flood-reports/{$report->id}/reject", [
            'rejection_reason' => 'Photo does not match location.',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', FloodReportStatus::Rejected->value)
        ->assertJsonPath('data.rejection_reason', 'Photo does not match location.');

    expect($report->fresh()->status)->toBe(FloodReportStatus::Rejected)
        ->and($report->fresh()->rejected_by)->toBe($officer->id);
});

it('requires a rejection reason', function () {
    Storage::fake('public');

    $officer = User::factory()->officer()->create();
    $tanod = User::factory()->tanod()->create();
    $report = FloodReport::factory()->for($tanod, 'user')->for($tanod->barangay, 'barangay')->create();

    $this->withToken($officer->createToken('t')->plainTextToken)
        ->postJson("/api/flood-reports/{$report->id}/reject", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['rejection_reason']);
});

it('forbids non-officers from rejecting a report', function () {
    Storage::fake('public');

    $staff = User::factory()->staff()->create();
    $tanod = User::factory()->tanod()->create();
    $report = FloodReport::factory()->for($tanod, 'user')->for($tanod->barangay, 'barangay')->create();

    $this->withToken($staff->createToken('t')->plainTextToken)
        ->postJson("/api/flood-reports/{$report->id}/reject", [
            'rejection_reason' => 'No.',
        ])
        ->assertForbidden();
});
it('rejects submission from a field user with no barangay before storing a photo', function () {
    Storage::fake('public');
    Http::fake();

    $user = User::factory()->tanod()->create(['barangay_id' => null]);

    $response = $this->withToken($user->createToken('t')->plainTextToken)
        ->post('/api/flood-reports', floodReportPayload());

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['barangay_id']);

    expect(FloodReport::count())->toBe(0);
    Storage::disk('public')->assertMissing('flood-reports');
});

function coveredBarangay(string $code = 'PH0305402001'): Barangay
{
    return Barangay::factory()->create(['code' => $code]);
}

it('flags a report whose coordinates fall outside the assigned barangay', function () {
    Storage::fake('public');
    Http::fake();

    $tanod = User::factory()->tanod()->forBarangay(coveredBarangay())->create();

    $this->withToken($tanod->createToken('t')->plainTextToken)
        ->post('/api/flood-reports', floodReportPayload())
        ->assertCreated()
        ->assertJsonPath('data.status', FloodReportStatus::Submitted->value)
        ->assertJsonFragment([
            'value' => AnomalyFlag::GpsOutsideBarangay->value,
            'label' => AnomalyFlag::GpsOutsideBarangay->label(),
        ]);

    expect(FloodReport::firstOrFail()->anomaly_flags->all())
        ->toContain(AnomalyFlag::GpsOutsideBarangay);
});

it('does not flag a report inside the assigned barangay', function () {
    Storage::fake('public');
    Http::fake();

    $tanod = User::factory()->tanod()->forBarangay(coveredBarangay())->create();

    $this->withToken($tanod->createToken('t')->plainTextToken)
        ->post('/api/flood-reports', floodReportPayload([
            'latitude' => 14.9606074,
            'longitude' => 120.8282337,
            'reported_at' => now()->toIso8601String(),
        ]))
        ->assertCreated()
        ->assertJsonPath('data.anomaly_flags', []);
});

it('flags a report that lands inside a different barangay', function () {
    Storage::fake('public');
    Http::fake();

    $tanod = User::factory()->tanod()->forBarangay(coveredBarangay('PH0305402001'))->create();

    $this->withToken($tanod->createToken('t')->plainTextToken)
        ->post('/api/flood-reports', floodReportPayload([
            'latitude' => 14.9793295,
            'longitude' => 120.8536699,
            'reported_at' => now()->toIso8601String(),
        ]))
        ->assertCreated()
        ->assertJsonFragment(['value' => AnomalyFlag::GpsOutsideBarangay->value]);
});

it('does not flag when containment cannot answer', function () {
    Storage::fake('public');
    Http::fake();

    $tanod = User::factory()->tanod()->create();

    $this->withToken($tanod->createToken('t')->plainTextToken)
        ->post('/api/flood-reports', floodReportPayload([
            'reported_at' => now()->toIso8601String(),
        ]))
        ->assertCreated()
        ->assertJsonPath('data.anomaly_flags', []);
});

it('flags a second report from the same reporter inside the duplicate window', function () {
    Storage::fake('public');
    Http::fake();

    $tanod = User::factory()->tanod()->create();
    $token = $tanod->createToken('t')->plainTextToken;

    $this->withToken($token)
        ->post('/api/flood-reports', floodReportPayload([
            'reported_at' => now()->subMinutes(10)->toIso8601String(),
        ]))
        ->assertCreated()
        ->assertJsonPath('data.anomaly_flags', []);

    $this->withToken($token)
        ->post('/api/flood-reports', floodReportPayload([
            'reported_at' => now()->subMinutes(5)->toIso8601String(),
        ]))
        ->assertCreated()
        ->assertJsonFragment(['value' => AnomalyFlag::DuplicateWindow->value]);
});

it('does not flag a second report outside the duplicate window', function () {
    Storage::fake('public');
    Http::fake();

    $tanod = User::factory()->tanod()->create();
    $token = $tanod->createToken('t')->plainTextToken;

    $this->withToken($token)
        ->post('/api/flood-reports', floodReportPayload([
            'reported_at' => now()->subMinutes(60)->toIso8601String(),
        ]))
        ->assertCreated();

    $this->withToken($token)
        ->post('/api/flood-reports', floodReportPayload([
            'reported_at' => now()->subMinutes(5)->toIso8601String(),
        ]))
        ->assertCreated()
        ->assertJsonPath('data.anomaly_flags', []);
});

it('flags a report whose observed time is far behind its receipt', function () {
    Storage::fake('public');
    Http::fake();

    $tanod = User::factory()->tanod()->create();

    $this->withToken($tanod->createToken('t')->plainTextToken)
        ->post('/api/flood-reports', floodReportPayload([
            'reported_at' => now()->subHours(2)->toIso8601String(),
        ]))
        ->assertCreated()
        ->assertJsonFragment(['value' => AnomalyFlag::ObservationReceiptDeviation->value]);
});
