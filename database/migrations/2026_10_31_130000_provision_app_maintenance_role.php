<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Provision the narrow role that fills a report's derived address (D-98).
 *
 * The address is written after the response or from a console command, where
 * no user identity exists. The runtime role may assume this role for one
 * transaction, but its grants cover only the columns the lookup reads and the
 * one column it writes.
 */
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
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_maintenance') THEN
        CREATE ROLE app_maintenance NOLOGIN;
    END IF;
END
$$;
SQL);

        $connection->statement('grant usage on schema public to app_maintenance');

        $connection->statement(
            'grant select (id, latitude, longitude, address) on table flood_reports to app_maintenance'
        );

        $connection->statement('grant update (address) on table flood_reports to app_maintenance');

        $connection->statement('grant app_maintenance to app_runtime');
    }

    public function down(): void
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $exists = $connection->selectOne(
            "select 1 as present from pg_roles where rolname = 'app_maintenance'"
        );

        if ($exists === null) {
            return;
        }

        $connection->statement(
            'revoke select (id, latitude, longitude, address) on table flood_reports from app_maintenance'
        );
        $connection->statement('revoke update (address) on table flood_reports from app_maintenance');
        $connection->statement('revoke usage on schema public from app_maintenance');
        $connection->statement('revoke app_maintenance from app_runtime');

        $connection->statement('drop role app_maintenance');
    }
};
