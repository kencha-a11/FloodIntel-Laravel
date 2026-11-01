<?php

use App\Support\DatabaseIntegrity;
use App\Support\Postgis;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Database-level integrity and the one spatial migration (FR-71, FR-72, D-68,
 * D-76). PostGIS and the constraints exist only on PostgreSQL; the SQLite test
 * path skips both, which is why every spatial claim is proven in tests/Postgres.
 */
return new class extends Migration
{
    public function up(): void
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $schema = Postgis::ensure($connection);

        DatabaseIntegrity::assertExistingRowsAreValid($connection);

        foreach (DatabaseIntegrity::ENUM_COLUMNS as $table => $columns) {
            foreach ($columns as $column => $enum) {
                $connection->statement(sprintf(
                    'ALTER TABLE %s ADD CONSTRAINT %s CHECK (%s)',
                    $table,
                    DatabaseIntegrity::constraintName($table, $column),
                    DatabaseIntegrity::checkClause($column, $enum),
                ));
            }
        }

        foreach (DatabaseIntegrity::RANGES as ['table' => $table, 'column' => $column, 'min' => $min, 'max' => $max]) {
            $connection->statement(sprintf(
                'ALTER TABLE %s ADD CONSTRAINT %s CHECK (%s BETWEEN %d AND %d)',
                $table,
                DatabaseIntegrity::rangeConstraintName($table, $column),
                $column,
                $min,
                $max,
            ));
        }

        // Boundaries are seeded from the served release by BarangaySeeder,
        // because migrations run before the rows they would attach to exist.
        $connection->statement(sprintf(
            'ALTER TABLE barangays ADD COLUMN geometry %1$s.geography(MultiPolygon, 4326)',
            $schema,
        ));
        $connection->statement('CREATE INDEX barangays_geometry_gist ON barangays USING gist (geometry)');

        $connection->statement(sprintf(
            'ALTER TABLE flood_reports ADD COLUMN position %1$s.geography(Point, 4326) '
            .'GENERATED ALWAYS AS (%2$s(%3$s(longitude, latitude), 4326)::%1$s.geography) STORED',
            $schema,
            Postgis::call($schema, 'ST_SetSRID'),
            Postgis::call($schema, 'ST_MakePoint'),
        ));
        $connection->statement('CREATE INDEX flood_reports_position_gist ON flood_reports USING gist (position)');

        $connection->statement('ANALYZE barangays');
        $connection->statement('ANALYZE flood_reports');
    }

    /**
     * Refuse rather than discard: dropping the constraints would silently
     * weaken the integrity claim, and the index is what GEO-07's query plans
     * are written against. Reversing this needs a forward migration that also
     * rewrites those queries.
     */
    public function down(): void
    {
        throw new LogicException(
            'The spatial and integrity migration is not reversible: removing the constraints would silently '
            .'weaken FR-71 and dropping the index would invalidate GEO-07\'s query plans. Write a forward '
            .'migration instead.'
        );
    }
};
