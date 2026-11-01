<?php

namespace App\Services;

use App\Geo\BoundaryRelease;
use App\Geo\BoundarySet;
use App\Geo\ContainmentResult;
use Illuminate\Support\Facades\Log;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * The server's authoritative answer for where a coordinate falls.
 *
 * The polygons come from the release the build copied into the server's own
 * resources and are verified against its manifest before use. Any failure to
 * load them answers "unknown" rather than guessing.
 */
final class BarangayContainment
{
    private const string ARTIFACT = 'apalit-barangays';

    private const int PRECISION = 7;

    /**
     * Loaded releases keyed by directory; false marks a directory that failed
     * to load, so a broken release is not re-read on every report.
     *
     * @var array<string, BoundaryRelease|false>
     */
    private static array $cache = [];

    private static bool $failureLogged = false;

    public function __construct(private readonly ?string $directory = null) {}

    /**
     * Locate a coordinate, optionally checking that the reporter's barangay is
     * covered by the release at all.
     */
    public function locate(float $latitude, float $longitude, ?string $barangayCode = null): ContainmentResult
    {
        if (! is_finite($latitude) || ! is_finite($longitude)
            || abs($latitude) > 90 || abs($longitude) > 180) {
            return ContainmentResult::unknown(ContainmentResult::REASON_INVALID_COORDINATE);
        }

        $release = $this->release();

        if ($release === null) {
            return ContainmentResult::unknown(ContainmentResult::REASON_ARTIFACT_UNAVAILABLE);
        }

        if ($barangayCode !== null && ! $release->boundaries->has($barangayCode)) {
            return ContainmentResult::unknown(
                ContainmentResult::REASON_BARANGAY_UNCOVERED,
                $release->release,
            );
        }

        $longitude = round($longitude, self::PRECISION);
        $latitude = round($latitude, self::PRECISION);

        if (! $release->boundaries->coversExtent($longitude, $latitude)) {
            return ContainmentResult::outside($release->release);
        }

        $code = $release->boundaries->locate($longitude, $latitude);

        return $code === null
            ? ContainmentResult::unknown(ContainmentResult::REASON_COVERAGE_GAP, $release->release)
            : ContainmentResult::inside($code, $release->release);
    }

    /**
     * The checksum-verified polygons, for callers that must fail loudly rather
     * than answer "unknown" — the boundary seeder is the only one.
     */
    public function verifiedBoundaries(): BoundarySet
    {
        $release = $this->release();

        if ($release === null) {
            throw new RuntimeException('The boundary release is unavailable or failed verification.');
        }

        return $release->boundaries;
    }

    private function release(): ?BoundaryRelease
    {
        $directory = $this->directory ?? resource_path('geo');

        if (! array_key_exists($directory, self::$cache)) {
            try {
                self::$cache[$directory] = $this->load($directory);
            } catch (Throwable $exception) {
                self::$cache[$directory] = false;
                $this->reportFailure($directory, $exception);
            }
        }

        return self::$cache[$directory] ?: null;
    }

    private function load(string $directory): BoundaryRelease
    {
        $manifest = $this->decode($directory.DIRECTORY_SEPARATOR.'manifest.json', 'manifest');

        $release = $manifest['release'] ?? null;
        $artifacts = $manifest['artifacts'] ?? null;

        if (! is_string($release) || $release === '' || ! is_array($artifacts)) {
            throw new RuntimeException('Boundary manifest has no release id or artifact list.');
        }

        $record = null;

        foreach ($artifacts as $artifact) {
            if (is_array($artifact) && ($artifact['name'] ?? null) === self::ARTIFACT) {
                $record = $artifact;

                break;
            }
        }

        if ($record === null) {
            throw new RuntimeException('Boundary manifest does not list the '.self::ARTIFACT.' artifact.');
        }

        $checksum = $record['sha256'] ?? null;
        $file = $directory.DIRECTORY_SEPARATOR.basename((string) ($record['path'] ?? ''));

        if (! is_string($checksum) || ! is_file($file)) {
            throw new RuntimeException('Boundary artifact is missing or unchecksummed.');
        }

        if (! hash_equals(strtolower($checksum), hash_file('sha256', $file))) {
            throw new RuntimeException('Boundary artifact does not match its manifest checksum.');
        }

        return new BoundaryRelease($this->polygons($this->decode($file, 'boundaries')), $release);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $path, string $label): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Boundary {$label} file not found at {$path}.");
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("Boundary {$label} file is not valid JSON: {$exception->getMessage()}");
        }

        if (! is_array($decoded)) {
            throw new RuntimeException("Boundary {$label} file is not a JSON object.");
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $collection
     */
    private function polygons(array $collection): BoundarySet
    {
        $features = $collection['features'] ?? null;

        if (! is_array($features) || $features === []) {
            throw new RuntimeException('Boundary file has no features.');
        }

        $polygons = [];

        foreach ($features as $feature) {
            $code = $feature['properties']['brgy_code'] ?? null;
            $type = $feature['geometry']['type'] ?? null;
            $coordinates = $feature['geometry']['coordinates'] ?? null;

            if (! is_string($code) || $code === '' || ! is_array($coordinates)) {
                throw new RuntimeException('Boundary file has a feature without brgy_code or coordinates.');
            }

            $parts = match ($type) {
                'Polygon' => [$coordinates],
                'MultiPolygon' => $coordinates,
                default => throw new RuntimeException("Unsupported boundary geometry type for {$code}."),
            };

            $rings = [];

            foreach ($parts as $part) {
                if (! is_array($part)) {
                    throw new RuntimeException("Boundary geometry for {$code} is malformed.");
                }

                foreach ($part as $ring) {
                    $rings[] = $this->ring($ring, $code);
                }
            }

            if ($rings === []) {
                throw new RuntimeException("Boundary geometry for {$code} has no rings.");
            }

            $polygons[$code] = $rings;
        }

        return new BoundarySet($polygons);
    }

    /**
     * @return list<array{0: float, 1: float}>
     */
    private function ring(mixed $coordinates, string $code): array
    {
        if (! is_array($coordinates)) {
            throw new RuntimeException("Boundary ring for {$code} is malformed.");
        }

        $ring = [];

        foreach ($coordinates as $point) {
            if (! is_array($point) || ! isset($point[0], $point[1])
                || ! is_numeric($point[0]) || ! is_numeric($point[1])) {
                throw new RuntimeException("Boundary ring for {$code} has an invalid coordinate.");
            }

            $ring[] = [(float) $point[0], (float) $point[1]];
        }

        if (count($ring) < 3) {
            throw new RuntimeException("Boundary ring for {$code} has fewer than three points.");
        }

        return $ring;
    }

    private function reportFailure(string $directory, Throwable $exception): void
    {
        if (self::$failureLogged) {
            return;
        }

        self::$failureLogged = true;

        Log::error('Barangay boundary release unavailable; containment answers unknown.', [
            'directory' => $directory,
            'message' => $exception->getMessage(),
        ]);
    }
}
