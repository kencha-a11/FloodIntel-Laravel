<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Close the grant half of CASE-009 after the fact.
 *
 * 2026_10_31_120000 grants a snapshot of the tables that existed then and
 * sets no default privileges, so every later migration must grant its own
 * table. 2026_11_01_000000 never did: app_runtime could neither write a
 * latency sample nor read the officer summary (SQLSTATE 42501) once the
 * platform's RLS blocker cleared. The table carries no per-tenant scope, so
 * it is on FR-64's unprotected list and row-level security stays off — this
 * migration states that explicitly rather than leaving it to the platform.
 */
return new class extends Migration
{
    public function up(): void
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $connection->unprepared('ALTER TABLE realtime_latency_samples DISABLE ROW LEVEL SECURITY');
        $connection->unprepared('GRANT SELECT, INSERT ON TABLE realtime_latency_samples TO app_runtime');
        $connection->unprepared('GRANT USAGE, SELECT ON SEQUENCE realtime_latency_samples_id_seq TO app_runtime');
    }

    public function down(): void
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $connection->unprepared('REVOKE ALL PRIVILEGES ON TABLE realtime_latency_samples FROM app_runtime');
        $connection->unprepared('REVOKE ALL PRIVILEGES ON SEQUENCE realtime_latency_samples_id_seq FROM app_runtime');
    }
};
