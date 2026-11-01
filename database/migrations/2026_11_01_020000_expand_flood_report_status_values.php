<?php

use App\Enums\FloodReportStatus;
use App\Support\DatabaseIntegrity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The status CHECK was generated before D-21 added `validated` and `returned`
 * (2026_10_31_140000_add_spatial_and_integrity_constraints). PostgreSQL only:
 * the SQLite path never had the constraint.
 */
return new class extends Migration
{
    public function up(): void
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        DatabaseIntegrity::assertExistingRowsAreValid($connection);

        $connection->statement(sprintf(
            'ALTER TABLE flood_reports DROP CONSTRAINT IF EXISTS %s',
            DatabaseIntegrity::constraintName('flood_reports', 'status'),
        ));

        $connection->statement(sprintf(
            'ALTER TABLE flood_reports ADD CONSTRAINT %s CHECK (%s)',
            DatabaseIntegrity::constraintName('flood_reports', 'status'),
            DatabaseIntegrity::checkClause('status', FloodReportStatus::class),
        ));
    }

    /**
     * Narrowing the list would silently invalidate any row the wider list allowed.
     */
    public function down(): void
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $outside = (int) $connection->scalar(
            "SELECT COUNT(*) FROM flood_reports WHERE status IN ('validated', 'returned')",
        );

        if ($outside > 0) {
            throw new LogicException(
                "Refusing to narrow flood_reports.status: {$outside} row(s) carry a status this migration introduced."
            );
        }

        $connection->statement(sprintf(
            'ALTER TABLE flood_reports DROP CONSTRAINT IF EXISTS %s',
            DatabaseIntegrity::constraintName('flood_reports', 'status'),
        ));

        $connection->statement(sprintf(
            "ALTER TABLE flood_reports ADD CONSTRAINT %s CHECK (status IS NULL OR status IN ('submitted', 'rejected'))",
            DatabaseIntegrity::constraintName('flood_reports', 'status'),
        ));
    }
};
