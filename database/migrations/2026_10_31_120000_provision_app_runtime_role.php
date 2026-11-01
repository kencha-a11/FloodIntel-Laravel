<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $connection->statement(<<<'SQL'
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_runtime') THEN
        CREATE ROLE app_runtime NOLOGIN;
    END IF;
END
$$;
SQL);

        $connection->statement('grant usage on schema public to app_runtime');

        $connection->statement(
            'grant select, insert, update, delete on table
                users, password_reset_tokens, sessions, cache, cache_locks,
                jobs, job_batches, failed_jobs, personal_access_tokens
             to app_runtime'
        );

        $connection->statement('grant select on table barangays to app_runtime');

        $connection->statement('grant select, insert, update on table flood_reports to app_runtime');

        $connection->statement('grant select, insert on table flood_impact_reports to app_runtime');

        $connection->statement('grant select, insert, update on table flood_events to app_runtime');

        $connection->statement('grant usage, select on all sequences in schema public to app_runtime');
    }

    public function down(): void
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $exists = $connection->selectOne(
            "select 1 as present from pg_roles where rolname = 'app_runtime'"
        );

        if ($exists === null) {
            return;
        }

        $connection->statement('revoke all privileges on all tables in schema public from app_runtime');
        $connection->statement('revoke all privileges on all sequences in schema public from app_runtime');
        $connection->statement('revoke usage on schema public from app_runtime');

        // The password is provisioned outside the repository, so a re-run must set it again.
        $connection->statement('drop role app_runtime');
    }
};
