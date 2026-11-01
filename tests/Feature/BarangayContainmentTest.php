<?php

use App\Geo\ContainmentResult;
use App\Services\BarangayContainment;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * @return array{release: string, points: list<array<string, mixed>>}
 */
function containmentFixture(): array
{
    return json_decode(
        (string) file_get_contents(__DIR__.'/../Fixtures/geo/containment-points.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
}

/**
 * @return array<string, mixed>
 */
function containmentPoint(string $name): array
{
    foreach (containmentFixture()['points'] as $point) {
        if ($point['name'] === $name) {
            return $point;
        }
    }

    throw new RuntimeException("No containment fixture point named {$name}.");
}

dataset('containment points', function (): array {
    $cases = [];

    foreach (containmentFixture()['points'] as $point) {
        $cases[$point['name']] = [$point];
    }

    return $cases;
});

it('answers every fixture point as the release does', function (array $point) {
    $result = (new BarangayContainment)->locate($point['lat'], $point['lon']);

    expect($result->status)->toBe($point['status'])
        ->and($result->code)->toBe($point['code'] ?? null)
        ->and($result->reason)->toBe($point['reason'] ?? null)
        ->and($result->release)->toBe(containmentFixture()['release']);
})->with('containment points');

it('resolves the shared edge to the lowest barangay code', function () {
    $point = containmentPoint('shared-edge');

    $result = (new BarangayContainment)->locate($point['lat'], $point['lon']);

    expect($result->status)->toBe(ContainmentResult::INSIDE)
        ->and($result->code)->toBe('PH0305402001');
});

it('gives the same answer when the coordinate moves below the released precision', function () {
    $point = containmentPoint('interior-PH0305402001');
    $service = new BarangayContainment;

    expect($service->locate($point['lat'] + 0.00000004, $point['lon'] - 0.00000004))
        ->toEqual($service->locate($point['lat'], $point['lon']));
});

it('answers unknown when the boundary artifact does not match its checksum', function () {
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'floodintel-geo-'.Str::random(8);
    File::copyDirectory(resource_path('geo'), $directory);

    try {
        $path = $directory.DIRECTORY_SEPARATOR.'apalit-barangays.geojson';
        file_put_contents($path, str_replace('Balucuc', 'Balucuc ', (string) file_get_contents($path)));

        $result = (new BarangayContainment($directory))->locate(14.95, 120.75);

        expect($result->status)->toBe(ContainmentResult::UNKNOWN)
            ->and($result->reason)->toBe(ContainmentResult::REASON_ARTIFACT_UNAVAILABLE);
    } finally {
        File::deleteDirectory($directory);
    }
});

it('answers unknown when the release is not present', function () {
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'floodintel-geo-'.Str::random(8);
    File::ensureDirectoryExists($directory);

    try {
        $result = (new BarangayContainment($directory))->locate(14.95, 120.75);

        expect($result->status)->toBe(ContainmentResult::UNKNOWN)
            ->and($result->reason)->toBe(ContainmentResult::REASON_ARTIFACT_UNAVAILABLE);
    } finally {
        File::deleteDirectory($directory);
    }
});

it('answers unknown for a coordinate that is not finite or in range', function (float $latitude, float $longitude) {
    $result = (new BarangayContainment)->locate($latitude, $longitude);

    expect($result->status)->toBe(ContainmentResult::UNKNOWN)
        ->and($result->reason)->toBe(ContainmentResult::REASON_INVALID_COORDINATE);
})->with([
    'infinite latitude' => [INF, 120.75],
    'not a number' => [NAN, 120.75],
    'latitude beyond the pole' => [90.5, 120.75],
    'longitude beyond the antimeridian' => [14.95, 180.5],
]);

it('answers unknown when the reporter barangay has no polygon in the release', function () {
    $point = containmentPoint('interior-PH0305402001');

    $result = (new BarangayContainment)->locate($point['lat'], $point['lon'], 'BRGY-001');

    expect($result->status)->toBe(ContainmentResult::UNKNOWN)
        ->and($result->reason)->toBe(ContainmentResult::REASON_BARANGAY_UNCOVERED);
});

it('supports the outside-barangay badge from the service alone', function () {
    $service = new BarangayContainment;
    $conflicts = fn (ContainmentResult $result, string $barangayCode): bool => $result->status === ContainmentResult::OUTSIDE
        || ($result->status === ContainmentResult::INSIDE && $result->code !== $barangayCode);

    $interior = containmentPoint('interior-PH0305402001');
    $gap = containmentPoint('gap-inside-extent');

    expect($conflicts($service->locate($interior['lat'], $interior['lon']), 'PH0305402001'))->toBeFalse()
        ->and($conflicts($service->locate($interior['lat'], $interior['lon']), 'PH0305402003'))->toBeTrue()
        ->and($conflicts($service->locate($gap['lat'], $gap['lon']), 'PH0305402001'))->toBeFalse();
});
