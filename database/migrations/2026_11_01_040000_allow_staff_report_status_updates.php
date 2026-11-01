<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * D-23 makes validate and return staff actions, so the runtime must be able
 * to write the status for a staff caller. The row policy widens to both
 * command-center roles and the table-level UPDATE grant narrows to the
 * columns a decision writes; nothing else on the row is writable by the
 * runtime role. `updated_at` is included because the ORM touches it on
 * every save.
 */
return new class extends Migration
{
    public function up(): void
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $connection->unprepared(<<<'SQL'
DROP POLICY IF EXISTS flood_reports_update ON flood_reports;

CREATE POLICY flood_reports_update ON flood_reports FOR UPDATE TO app_runtime
USING (app_user_role() IN ('mdrrmo_officer', 'mdrrmo_staff'))
WITH CHECK (app_user_role() IN ('mdrrmo_officer', 'mdrrmo_staff'));

REVOKE UPDATE ON TABLE flood_reports FROM app_runtime;
GRANT UPDATE (status, rejected_at, rejected_by, rejection_reason, updated_at) ON TABLE flood_reports TO app_runtime;
SQL);
    }

    public function down(): void
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $connection->unprepared(<<<'SQL'
DROP POLICY IF EXISTS flood_reports_update ON flood_reports;

CREATE POLICY flood_reports_update ON flood_reports FOR UPDATE TO app_runtime
USING (app_user_role() = 'mdrrmo_officer')
WITH CHECK (app_user_role() = 'mdrrmo_officer');

REVOKE UPDATE (status, rejected_at, rejected_by, rejection_reason, updated_at) ON TABLE flood_reports FROM app_runtime;
GRANT UPDATE ON TABLE flood_reports TO app_runtime;
SQL);
    }
};
