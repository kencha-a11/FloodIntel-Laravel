<?php

use App\Enums\FloodLevel;
use App\Enums\FloodReportStatus;
use App\Enums\UserRole;
use App\Models\FloodReport;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(LazilyRefreshDatabase::class);

function floodReportPayload(array $overrides = []): array
{
    return array_merge([
        'flood_level' => FloodLevel::Knee->value,
        'latitude' => 14.5995,
        'longitude' => 120.9842,
        'gps_accuracy' => 12.5,
        'photo' => UploadedFile::fake()->create('flood.jpg', 100, 'image/jpeg'),
        'notes' => 'Water rising near the school.',
        'reported_at' => now()->subMinutes(5)->toIso8601String(),
    ], $overrides);
}

it('lets a tanod submit a flood report with a photo', function () {
    Storage::fake('public');

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

    $captain = User::factory()->captain()->create();

    $this->withToken($captain->createToken('t')->plainTextToken)
        ->post('/api/flood-reports', floodReportPayload())
        ->assertCreated();
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

it('validates the flood level', function () {
    Storage::fake('public');

    $tanod = User::factory()->tanod()->create();

    $this->withToken($tanod->createToken('t')->plainTextToken)
        ->post('/api/flood-reports', floodReportPayload(['flood_level' => 'tsunami']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['flood_level']);
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
