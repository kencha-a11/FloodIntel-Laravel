<?php

namespace Database\Seeders;

use App\Models\Barangay;
use App\Services\BarangayContainment;
use App\Support\Postgis;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class BarangaySeeder extends Seeder
{
    /**
     * Reconcile the barangay table with the served boundary artifact.
     *
     * Rows are never deleted: flood reports reference barangays with a
     * restricting foreign key, so a superseded row is deactivated instead.
     */
    public function run(): void
    {
        $barangays = $this->readArtifact();

        DB::transaction(function () use ($barangays): void {
            foreach ($barangays as $barangay) {
                Barangay::query()->updateOrCreate(
                    ['code' => $barangay['code']],
                    ['name' => $barangay['name'], 'is_active' => true],
                );
            }

            Barangay::query()
                ->whereNotIn('code', array_column($barangays, 'code'))
                ->where('is_active', true)
                ->update(['is_active' => false]);

            $this->syncGeometry();
        });
    }

    /**
     * Attach the polygons to the rows the spatial migration added a column for.
     *
     * Seeding, not migration, is where geometry lands: migrations run before
     * the rows a polygon would attach to exist. The polygons come from the
     * same manifest-verified release containment reads (D-95).
     */
    private function syncGeometry(): void
    {
        if (! Schema::hasColumn('barangays', 'geometry')) {
            return;
        }

        $schema = Postgis::schema(DB::connection());

        if ($schema === null) {
            return;
        }

        $boundaries = (new BarangayContainment)->verifiedBoundaries();

        foreach ($boundaries->codes() as $code) {
            DB::update(sprintf(
                'UPDATE barangays SET geometry = %s(?, 4326)::%s.geography, updated_at = now() WHERE code = ?',
                Postgis::call($schema, 'ST_GeomFromText'),
                $schema,
            ), [$this->multiPolygon($boundaries->rings($code)), $code]);
        }
    }

    /**
     * @param  list<list<array{0: float, 1: float}>>  $rings
     */
    private function multiPolygon(array $rings): string
    {
        if ($rings === []) {
            throw new RuntimeException('A barangay in the boundary release has no rings.');
        }

        $parts = [];

        foreach ($rings as $ring) {
            $points = array_map(
                static fn (array $point): string => sprintf('%.7F %.7F', $point[0], $point[1]),
                $ring,
            );

            if ($points[0] !== $points[count($points) - 1]) {
                $points[] = $points[0];
            }

            $parts[] = '(('.implode(', ', $points).'))';
        }

        return 'MULTIPOLYGON('.implode(', ', $parts).')';
    }

    /**
     * @return list<array{code: string, name: string}>
     */
    private function readArtifact(): array
    {
        $path = resource_path('geo/apalit-barangays.geojson');

        if (! is_file($path)) {
            throw new RuntimeException("Barangay boundary artifact not found at {$path}.");
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded) || ! isset($decoded['features']) || ! is_array($decoded['features'])) {
            throw new RuntimeException("Barangay boundary artifact at {$path} is not a GeoJSON FeatureCollection.");
        }

        $barangays = [];

        foreach ($decoded['features'] as $feature) {
            $code = $feature['properties']['brgy_code'] ?? null;
            $name = $feature['properties']['brgy_name'] ?? null;

            if (! is_string($code) || $code === '' || ! is_string($name) || $name === '') {
                throw new RuntimeException("Barangay boundary artifact at {$path} has a feature without brgy_code or brgy_name.");
            }

            if (isset($barangays[$code])) {
                throw new RuntimeException("Barangay boundary artifact at {$path} repeats brgy_code {$code}.");
            }

            $barangays[$code] = ['code' => $code, 'name' => $name];
        }

        if (count($barangays) !== 12) {
            throw new RuntimeException(
                'Barangay boundary artifact must contain all 12 barangays; found '.count($barangays).'.',
            );
        }

        return array_values($barangays);
    }
}
