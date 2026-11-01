<?php

namespace Tests\Postgres;

use App\Enums\AlertLevel;
use App\Enums\FloodImpactStatus;
use App\Enums\FloodLevel;
use App\Enums\FloodReportStatus;
use App\Enums\FloodType;
use App\Models\Barangay;
use App\Models\FloodEvent;
use App\Models\User;
use App\Support\DatabaseIntegrity;
use Database\Seeders\BarangaySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

final class SpatialConstraintTest extends PostgresTestCase
{
    use LazilyRefreshDatabase;

    private Barangay $barangay;

    private User $reporter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->barangay = Barangay::factory()->create(['name' => 'San Juan', 'code' => 'SJ']);
        $this->reporter = User::factory()->tanod()->forBarangay($this->barangay)->create();
    }

    public function test_every_enum_case_is_accepted_by_the_database(): void
    {
        foreach (FloodLevel::cases() as $case) {
            DB::table('flood_reports')->insert($this->reportRow(['flood_level' => $case->value]));
        }

        foreach (AlertLevel::cases() as $case) {
            DB::table('flood_reports')->insert($this->reportRow(['alert_level' => $case->value]));
        }

        foreach (FloodType::cases() as $case) {
            DB::table('flood_reports')->insert($this->reportRow(['flood_type' => $case->value]));
        }

        foreach (FloodReportStatus::cases() as $case) {
            DB::table('flood_reports')->insert($this->reportRow(['status' => $case->value]));
        }

        $event = FloodEvent::create(['title' => 'Typhoon', 'starts_at' => now()]);

        foreach (FloodImpactStatus::cases() as $case) {
            DB::table('flood_impact_reports')->insert([
                'user_id' => $this->reporter->id,
                'barangay_id' => $this->barangay->id,
                'event_id' => $event->id,
                'families' => 1,
                'individuals' => 4,
                'reported_at' => now(),
                'status' => $case->value,
            ]);
        }

        $expected = count(FloodLevel::cases())
            + count(AlertLevel::cases())
            + count(FloodType::cases())
            + count(FloodReportStatus::cases());

        $this->assertSame($expected, DB::table('flood_reports')->count());
        $this->assertSame(count(FloodImpactStatus::cases()), DB::table('flood_impact_reports')->count());
    }

    public function test_a_value_outside_the_enum_is_rejected_by_the_database(): void
    {
        foreach (['flood_level', 'alert_level', 'flood_type', 'status'] as $column) {
            $this->assertConstraintViolation(
                fn () => DB::table('flood_reports')->insert($this->reportRow([$column => 'not_a_case'])),
            );
        }

        $event = FloodEvent::create(['title' => 'Typhoon', 'starts_at' => now()]);

        $this->assertConstraintViolation(fn () => DB::table('flood_impact_reports')->insert([
            'user_id' => $this->reporter->id,
            'barangay_id' => $this->barangay->id,
            'event_id' => $event->id,
            'families' => 1,
            'individuals' => 4,
            'reported_at' => now(),
            'status' => 'not_a_case',
        ]));
    }

    public function test_out_of_range_coordinates_are_rejected_by_the_database(): void
    {
        $this->assertConstraintViolation(
            fn () => DB::table('flood_reports')->insert($this->reportRow(['latitude' => 90.0000001])),
        );

        $this->assertConstraintViolation(
            fn () => DB::table('flood_reports')->insert($this->reportRow(['longitude' => -180.0000001])),
        );

        DB::table('flood_reports')->insert($this->reportRow(['latitude' => -90, 'longitude' => 180]));

        $this->assertSame(1, DB::table('flood_reports')->count(), 'The bounds themselves are allowed.');
    }

    public function test_the_constraints_are_generated_from_the_current_enum_cases(): void
    {
        foreach (DatabaseIntegrity::ENUM_COLUMNS as $table => $columns) {
            foreach ($columns as $column => $enum) {
                $definition = (string) DB::scalar(
                    'select pg_get_constraintdef(oid) from pg_constraint where conname = ?',
                    [DatabaseIntegrity::constraintName($table, $column)],
                );

                foreach (DatabaseIntegrity::values($enum) as $value) {
                    $this->assertStringContainsString(
                        "'{$value}'",
                        $definition,
                        "The {$table}.{$column} constraint does not list the {$enum} case '{$value}'.",
                    );
                }
            }
        }
    }

    public function test_the_migration_refuses_existing_rows_that_violate_the_constraints(): void
    {
        DB::statement('alter table flood_reports drop constraint flood_reports_flood_level_allowed_values');

        DB::table('flood_reports')->insert($this->reportRow(['flood_level' => 'waistdeep']));

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('flood_reports.flood_level');

            DatabaseIntegrity::assertExistingRowsAreValid(DB::connection());
        } finally {
            DB::table('flood_reports')->where('flood_level', 'waistdeep')->delete();
        }
    }

    public function test_the_rollback_refuses_and_leaves_the_rows_intact(): void
    {
        DB::table('flood_reports')->insert($this->reportRow());

        $migration = require database_path('migrations/2026_10_31_140000_add_spatial_and_integrity_constraints.php');

        try {
            $migration->down();
            $this->fail('The spatial migration was expected to refuse a rollback.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('not reversible', $exception->getMessage());
        }

        $this->assertSame(1, DB::table('flood_reports')->count());
        $this->assertSame('knee', DB::table('flood_reports')->value('flood_level'));
    }

    public function test_the_spatial_columns_and_indexes_exist(): void
    {
        $this->assertSame('geography', $this->udtName('barangays', 'geometry'));
        $this->assertSame('geography', $this->udtName('flood_reports', 'position'));
        $this->assertSame('ALWAYS', $this->generation('flood_reports', 'position'));

        $this->assertStringContainsString('gist', strtolower($this->indexDefinition('barangays_geometry_gist')));
        $this->assertStringContainsString('gist', strtolower($this->indexDefinition('flood_reports_position_gist')));
    }

    public function test_the_generated_position_derives_from_the_coordinate_pair(): void
    {
        DB::table('flood_reports')->insert($this->reportRow(['latitude' => 14.9501234, 'longitude' => 120.7504567]));

        $position = (string) DB::scalar('select public.ST_AsText(position::public.geometry) from flood_reports');

        $this->assertSame('POINT(120.7504567 14.9501234)', $position);
    }

    public function test_the_seeder_attaches_the_verified_boundaries_to_the_rows(): void
    {
        $this->seed(BarangaySeeder::class);

        $this->assertSame(12, DB::table('barangays')->whereNotNull('geometry')->count());
        $this->assertSame(12, DB::table('barangays')->where('is_active', true)->count());

        $unmatched = (int) DB::scalar(<<<'SQL'
            SELECT count(*) FROM barangays
            WHERE geometry IS NOT NULL
              AND NOT public.ST_Intersects(geometry, public.ST_PointOnSurface(geometry::public.geometry)::public.geography)
        SQL);

        $this->assertSame(0, $unmatched, 'Every seeded polygon must claim a point of its own surface.');
    }

    public function test_the_containment_query_uses_the_boundary_index(): void
    {
        $this->seed(BarangaySeeder::class);

        // Twelve rows never beat a sequential scan on cost; the question here is
        // whether the planner *can* use the GiST index for this predicate.
        DB::statement('set local enable_seqscan = off');

        $plan = $this->plan(<<<'SQL'
            SELECT code FROM barangays
            WHERE public.ST_Intersects(geometry, public.ST_SetSRID(public.ST_MakePoint(120.7504567, 14.9501234), 4326)::public.geography)
        SQL);

        $this->assertStringContainsString('barangays_geometry_gist', $plan);
    }

    public function test_a_time_windowed_bounding_box_uses_the_position_index(): void
    {
        $this->seedReports(5000);
        DB::statement('analyze flood_reports');

        $plan = $this->plan(<<<'SQL'
            SELECT id FROM flood_reports
            WHERE position && public.ST_MakeEnvelope(120.7490, 14.9490, 120.7515, 14.9510, 4326)::public.geography
              AND reported_at >= now() - interval '7 days'
        SQL);

        $this->assertStringContainsString('flood_reports_position_gist', $plan);
    }

    public function test_a_proximity_lookup_uses_the_position_index(): void
    {
        $this->seedReports(5000);
        DB::statement('analyze flood_reports');

        $plan = $this->plan(<<<'SQL'
            SELECT id FROM flood_reports
            WHERE public.ST_DWithin(position, public.ST_SetSRID(public.ST_MakePoint(120.7504567, 14.9501234), 4326)::public.geography, 250)
        SQL);

        $this->assertStringContainsString('flood_reports_position_gist', $plan);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function reportRow(array $overrides = []): array
    {
        return array_merge([
            'user_id' => $this->reporter->id,
            'barangay_id' => $this->barangay->id,
            'flood_level' => 'knee',
            'latitude' => 14.9501234,
            'longitude' => 120.7504567,
            'photo_path' => 'flood-reports/probe.jpg',
            'reported_at' => now(),
            'status' => 'submitted',
        ], $overrides);
    }

    private function seedReports(int $count): void
    {
        DB::statement(<<<'SQL'
            INSERT INTO flood_reports
                (user_id, barangay_id, flood_level, latitude, longitude, photo_path, reported_at, status, created_at, updated_at)
            SELECT ?, ?, 'knee',
                   14.90 + (series % 100) * 0.001,
                   120.70 + (series / 100) * 0.001,
                   'flood-reports/probe.jpg',
                   now() - (series % 30) * interval '1 day',
                   'submitted', now(), now()
            FROM generate_series(1, ?) AS series
        SQL, [$this->reporter->id, $this->barangay->id, $count]);
    }

    private function plan(string $sql): string
    {
        $rows = DB::select('EXPLAIN '.$sql);

        return implode("\n", array_map(static fn (object $row): string => (string) $row->{'QUERY PLAN'}, $rows));
    }

    private function assertConstraintViolation(callable $callback): void
    {
        try {
            DB::transaction($callback);
            $this->fail('The statement was expected to violate a database constraint.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->getCode(), $exception->getMessage());
        }
    }

    private function udtName(string $table, string $column): string
    {
        return (string) DB::scalar(
            'select udt_name from information_schema.columns where table_name = ? and column_name = ?',
            [$table, $column],
        );
    }

    private function generation(string $table, string $column): string
    {
        return (string) DB::scalar(
            'select is_generated from information_schema.columns where table_name = ? and column_name = ?',
            [$table, $column],
        );
    }

    private function indexDefinition(string $index): string
    {
        return (string) DB::scalar('select indexdef from pg_indexes where indexname = ?', [$index]);
    }
}
