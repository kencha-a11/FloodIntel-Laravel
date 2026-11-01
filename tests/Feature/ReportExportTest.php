<?php

use App\Enums\AlertLevel;
use App\Enums\FloodReportStatus;
use App\Enums\FloodType;
use App\Models\Barangay;
use App\Models\FloodReport;
use App\Models\User;
use App\Services\FloodReportTransitions;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
});

function exportReport(Barangay $barangay, ?User $reporter = null, array $attributes = []): FloodReport
{
    $reporter ??= User::factory()->tanod()->forBarangay($barangay)->create();

    return FloodReport::factory()
        ->for($reporter, 'user')
        ->for($barangay, 'barangay')
        ->create($attributes);
}

/**
 * @return array<int, array<int, string>>
 */
function exportCsvRows(string $csv): array
{
    $handle = fopen('php://memory', 'r+');
    fwrite($handle, $csv);
    rewind($handle);

    $rows = [];

    while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
        $rows[] = $row;
    }

    fclose($handle);

    return $rows;
}

function exportColumnIndex(array $header, string $column): int
{
    $index = array_search($column, $header, true);

    expect($index)->not->toBeFalse();

    return $index;
}

it('streams the filtered log with the row count in a header', function () {
    $staff = User::factory()->staff()->create();
    $barangay = Barangay::factory()->create();

    $validated = exportReport($barangay, null, [
        'reported_at' => '2026-11-01 09:55:00',
        'created_at' => '2026-11-01 10:00:00',
        'updated_at' => '2026-11-01 10:00:00',
        'alert_level' => AlertLevel::RedAlert,
        'flood_type' => FloodType::Ponding,
        'depth_cm' => 85,
        'address' => 'Purok 3, San Juan',
        'notes' => 'Water reached the gate.',
    ]);
    app(FloodReportTransitions::class)->validate($validated, $staff);

    exportReport($barangay);
    $returned = exportReport($barangay);
    app(FloodReportTransitions::class)->return($returned, $staff, 'Landmark does not match the coordinates.');

    $response = $this->withToken($staff->createToken('t')->plainTextToken)
        ->get('/api/flood-reports/export?status=validated');

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->assertHeader('X-Report-Count', '1')
        ->assertDownload();

    expect($response->baseResponse)->toBeInstanceOf(StreamedResponse::class);

    $rows = exportCsvRows($response->streamedContent());
    $header = $rows[0];

    expect($rows)->toHaveCount(2)
        ->and($header)->toContain('report_id')
        ->and($header)->toContain('photo_url')
        ->and($header)->not->toContain('photo_path');

    $row = $rows[1];

    expect((int) $row[exportColumnIndex($header, 'report_id')])->toBe($validated->id)
        ->and($row[exportColumnIndex($header, 'barangay')])->toBe($barangay->name)
        ->and($row[exportColumnIndex($header, 'flood_type')])->toBe(FloodType::Ponding->label())
        ->and($row[exportColumnIndex($header, 'status')])->toBe(FloodReportStatus::Validated->value)
        ->and($row[exportColumnIndex($header, 'alert_level')])->toBe(AlertLevel::RedAlert->value)
        ->and($row[exportColumnIndex($header, 'depth_cm')])->toBe('85')
        ->and($row[exportColumnIndex($header, 'latency_seconds')])->toBe('300');
});

it('refuses guests and field roles', function () {
    $this->get('/api/flood-reports/export')->assertUnauthorized();

    foreach ([User::factory()->tanod()->create(), User::factory()->captain()->create(), User::factory()->rescueLeader()->create()] as $fieldUser) {
        $this->withToken($fieldUser->createToken('t')->plainTextToken)
            ->get('/api/flood-reports/export')
            ->assertForbidden();
    }
});

it('spans barangays for the command center and narrows when the filter names one', function () {
    $officer = User::factory()->officer()->create();
    $sanJuan = Barangay::factory()->create(['name' => 'San Juan']);
    $sanVicente = Barangay::factory()->create(['name' => 'San Vicente']);

    exportReport($sanJuan);
    exportReport($sanVicente);

    $all = $this->withToken($officer->createToken('t')->plainTextToken)
        ->get('/api/flood-reports/export');

    $all->assertHeader('X-Report-Count', '2');
    expect(exportCsvRows($all->streamedContent()))->toHaveCount(3);

    $narrowed = $this->withToken($officer->createToken('t')->plainTextToken)
        ->get("/api/flood-reports/export?barangay_id={$sanVicente->id}");

    $narrowed->assertHeader('X-Report-Count', '1');
    $rows = exportCsvRows($narrowed->streamedContent());

    expect($rows)->toHaveCount(2)
        ->and($rows[1][exportColumnIndex($rows[0], 'barangay')])->toBe('San Vicente');
});

it('applies one free-text match to the queue and the export', function () {
    $staff = User::factory()->staff()->create();
    $barangay = Barangay::factory()->create();

    $maria = User::factory()->tanod()->forBarangay($barangay)->create([
        'first_name' => 'Maria',
        'last_name' => 'Santos',
    ]);

    exportReport($barangay, null, ['notes' => 'Knee-deep beside the bridge']);
    exportReport($barangay, null, ['landmark' => 'Bayan Bridge']);
    exportReport($barangay, $maria, ['notes' => 'River level rising']);
    exportReport($barangay, null, ['notes' => 'Dry road']);

    $token = $staff->createToken('t')->plainTextToken;

    $queue = $this->withToken($token)
        ->getJson('/api/flood-reports?q=bridge')
        ->assertOk()
        ->json('data');

    $queueIds = collect($queue)->pluck('id')->sort()->values()->all();

    $export = $this->withToken($token)->get('/api/flood-reports/export?q=bridge');
    $rows = exportCsvRows($export->streamedContent());
    $exportIds = collect(array_slice($rows, 1))
        ->map(fn (array $row): int => (int) $row[exportColumnIndex($rows[0], 'report_id')])
        ->sort()
        ->values()
        ->all();

    expect($queueIds)->toHaveCount(2)
        ->and($exportIds)->toBe($queueIds);

    $byReporter = $this->withToken($token)->get('/api/flood-reports/export?q=santos');
    $reporterRows = exportCsvRows($byReporter->streamedContent());

    expect($reporterRows)->toHaveCount(2)
        ->and($reporterRows[1][exportColumnIndex($reporterRows[0], 'reporter_name')])->toBe('Maria Santos');
});

it('treats wildcards literally and escapes delimiters, quotes, and newlines', function () {
    $staff = User::factory()->staff()->create();
    $barangay = Barangay::factory()->create();

    $tricky = exportReport($barangay, null, [
        'address' => 'Purok 3, San Juan',
        'notes' => "Culvert \"A\", near the\nrice field",
        'reported_at' => '2026-11-01 09:00:00',
    ]);

    exportReport($barangay, null, ['notes' => '100% flooded', 'reported_at' => '2026-11-01 09:05:00']);

    $response = $this->withToken($staff->createToken('t')->plainTextToken)
        ->get('/api/flood-reports/export?q='.urlencode('%'));

    $rows = exportCsvRows($response->streamedContent());

    expect($rows)->toHaveCount(2)
        ->and((int) $rows[1][exportColumnIndex($rows[0], 'report_id')])->not->toBe($tricky->id)
        ->and($rows[1][exportColumnIndex($rows[0], 'notes')])->toBe('100% flooded');

    $plain = $this->withToken($staff->createToken('t')->plainTextToken)
        ->get('/api/flood-reports/export');

    $plainRows = exportCsvRows($plain->streamedContent());
    $row = $plainRows[2];

    expect($row[exportColumnIndex($plainRows[0], 'address')])->toBe('Purok 3, San Juan')
        ->and($row[exportColumnIndex($plainRows[0], 'notes')])->toBe("Culvert \"A\", near the\nrice field");
});

it('writes the photo URL and never the photo bytes', function () {
    Storage::fake('public');
    Storage::disk('public')->put('flood-reports/1/proof.jpg', 'PHOTO-BYTES-MARKER');

    $staff = User::factory()->staff()->create();
    $barangay = Barangay::factory()->create();

    exportReport($barangay, null, ['photo_path' => 'flood-reports/1/proof.jpg']);

    $csv = $this->withToken($staff->createToken('t')->plainTextToken)
        ->get('/api/flood-reports/export')
        ->streamedContent();

    expect($csv)->not->toContain('PHOTO-BYTES-MARKER')
        ->and($csv)->toContain('/storage/flood-reports/1/proof.jpg');
});

it('reads a large log in chunks instead of materializing it', function () {
    $staff = User::factory()->staff()->create();
    $barangay = Barangay::factory()->create();
    $reporter = User::factory()->tanod()->forBarangay($barangay)->create();

    foreach (range(1, 60) as $i) {
        exportReport($barangay, $reporter, ['reported_at' => now()->subSeconds($i)]);
    }

    $response = $this->withToken($staff->createToken('t')->plainTextToken)
        ->get('/api/flood-reports/export');

    $response->assertHeader('X-Report-Count', '60');
    expect($response->baseResponse)->toBeInstanceOf(StreamedResponse::class)
        ->and($response->baseResponse->getContent())->toBeFalse()
        ->and(exportCsvRows($response->streamedContent()))->toHaveCount(61);
});

it('validates the filter contract', function (string $query) {
    $staff = User::factory()->staff()->create();

    $this->withToken($staff->createToken('t')->plainTextToken)
        ->getJson('/api/flood-reports/export?'.$query)
        ->assertUnprocessable();
})->with([
    'unknown status' => 'status=archived',
    'unknown alert tier' => 'alert_level=level_4',
    'inverted date window' => 'from=2026-11-02&to=2026-11-01',
    'overlong search term' => 'q='.str_repeat('a', 101),
    'unknown barangay' => 'barangay_id=999999',
]);
