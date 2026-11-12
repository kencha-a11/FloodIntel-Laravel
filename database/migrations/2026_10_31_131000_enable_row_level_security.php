<?php

use App\Support\RlsScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Enable row-level security for the first list in FR-64.
 *
 * users stays on the second list: login, password reset, email verification,
 * and Sanctum token resolution all read it before an identity can exist
 * (D-97). The address backfill writes through app_maintenance, whose grants
 * cover only the columns it reads and the one column it writes (D-98).
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
CREATE OR REPLACE FUNCTION app_user_id() RETURNS bigint
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
  SELECT id FROM users
  WHERE id = nullif(current_setting('app.user_id', true), '')::bigint
$$;

CREATE OR REPLACE FUNCTION app_user_role() RETURNS text
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
  SELECT role::text FROM users
  WHERE id = nullif(current_setting('app.user_id', true), '')::bigint
$$;

CREATE OR REPLACE FUNCTION app_user_barangay() RETURNS bigint
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
  SELECT barangay_id FROM users
  WHERE id = nullif(current_setting('app.user_id', true), '')::bigint
$$;

REVOKE ALL ON FUNCTION app_user_id() FROM PUBLIC;
REVOKE ALL ON FUNCTION app_user_role() FROM PUBLIC;
REVOKE ALL ON FUNCTION app_user_barangay() FROM PUBLIC;

GRANT EXECUTE ON FUNCTION app_user_id() TO app_runtime;
GRANT EXECUTE ON FUNCTION app_user_role() TO app_runtime;
GRANT EXECUTE ON FUNCTION app_user_barangay() TO app_runtime;
SQL);

        // Disable RLS on tables that should be unprotected first (tables that
        // were created after this migration was originally written may have
        // had RLS enabled by default or by a previous incomplete run).
        $connection->unprepared(<<<'SQL'
ALTER TABLE migrations DISABLE ROW LEVEL SECURITY;
ALTER TABLE password_reset_tokens DISABLE ROW LEVEL SECURITY;
ALTER TABLE sessions DISABLE ROW LEVEL SECURITY;
ALTER TABLE cache DISABLE ROW LEVEL SECURITY;
ALTER TABLE cache_locks DISABLE ROW LEVEL SECURITY;
ALTER TABLE jobs DISABLE ROW LEVEL SECURITY;
ALTER TABLE job_batches DISABLE ROW LEVEL SECURITY;
ALTER TABLE failed_jobs DISABLE ROW LEVEL SECURITY;
ALTER TABLE users DISABLE ROW LEVEL SECURITY;
ALTER TABLE personal_access_tokens DISABLE ROW LEVEL SECURITY;
ALTER TABLE realtime_latency_samples DISABLE ROW LEVEL SECURITY;
SQL);

        $connection->unprepared(<<<'SQL'
ALTER TABLE flood_reports ENABLE ROW LEVEL SECURITY;

CREATE POLICY flood_reports_select ON flood_reports FOR SELECT TO app_runtime
USING (
  app_user_role() IN ('mdrrmo_officer', 'mdrrmo_staff')
  OR (app_user_role() IN ('barangay_captain', 'barangay_tanod')
      AND barangay_id = app_user_barangay())
  OR user_id = app_user_id()
);

CREATE POLICY flood_reports_insert ON flood_reports FOR INSERT TO app_runtime
WITH CHECK (user_id = app_user_id() AND barangay_id = app_user_barangay());

CREATE POLICY flood_reports_update ON flood_reports FOR UPDATE TO app_runtime
USING (app_user_role() = 'mdrrmo_officer')
WITH CHECK (app_user_role() = 'mdrrmo_officer');

-- app_runtime is a member of app_maintenance so it can SET ROLE, and a
-- policy TO a role also applies to its members. The current_user test keeps
-- both policies exclusive to an actual SET ROLE, so the runtime role's own
-- identity rules are never widened by the membership. The read policy sees
-- every row because PostgreSQL checks the updated row against it, and a
-- backfilled row no longer matches "address IS NULL"; the write policy still
-- reaches only rows that have no address yet.
CREATE POLICY flood_reports_maintenance_select ON flood_reports FOR SELECT TO app_maintenance
USING (current_user = 'app_maintenance');

CREATE POLICY flood_reports_maintenance_update ON flood_reports FOR UPDATE TO app_maintenance
USING (current_user = 'app_maintenance' AND address IS NULL)
WITH CHECK (current_user = 'app_maintenance' AND address IS NOT NULL);
SQL);

        $connection->unprepared(<<<'SQL'
ALTER TABLE flood_impact_reports ENABLE ROW LEVEL SECURITY;

CREATE POLICY flood_impact_reports_select ON flood_impact_reports FOR SELECT TO app_runtime
USING (
  app_user_role() IN ('mdrrmo_officer', 'mdrrmo_staff')
  OR (app_user_role() IN ('barangay_captain', 'barangay_tanod')
      AND barangay_id = app_user_barangay())
  OR user_id = app_user_id()
);

CREATE POLICY flood_impact_reports_insert ON flood_impact_reports FOR INSERT TO app_runtime
WITH CHECK (user_id = app_user_id() AND barangay_id = app_user_barangay());
SQL);

        $connection->unprepared(<<<'SQL'
ALTER TABLE flood_events ENABLE ROW LEVEL SECURITY;

CREATE POLICY flood_events_select ON flood_events FOR SELECT TO app_runtime
USING (app_user_id() IS NOT NULL);

CREATE POLICY flood_events_write ON flood_events FOR INSERT TO app_runtime
WITH CHECK (app_user_role() IN ('mdrrmo_officer', 'mdrrmo_staff'));

CREATE POLICY flood_events_update ON flood_events FOR UPDATE TO app_runtime
USING (app_user_role() IN ('mdrrmo_officer', 'mdrrmo_staff'))
WITH CHECK (app_user_role() IN ('mdrrmo_officer', 'mdrrmo_staff'));
SQL);

        $connection->unprepared(<<<'SQL'
ALTER TABLE barangays ENABLE ROW LEVEL SECURITY;

CREATE POLICY barangays_select ON barangays FOR SELECT TO app_runtime
USING (app_user_id() IS NOT NULL);
SQL);

        RlsScope::assertEveryTableDecided($connection);
    }

    public function down(): void
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $connection->unprepared(<<<'SQL'
DROP POLICY IF EXISTS flood_reports_select ON flood_reports;
DROP POLICY IF EXISTS flood_reports_insert ON flood_reports;
DROP POLICY IF EXISTS flood_reports_update ON flood_reports;
DROP POLICY IF EXISTS flood_reports_maintenance_select ON flood_reports;
DROP POLICY IF EXISTS flood_reports_maintenance_update ON flood_reports;
ALTER TABLE flood_reports DISABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS flood_impact_reports_select ON flood_impact_reports;
DROP POLICY IF EXISTS flood_impact_reports_insert ON flood_impact_reports;
ALTER TABLE flood_impact_reports DISABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS flood_events_select ON flood_events;
DROP POLICY IF EXISTS flood_events_write ON flood_events;
DROP POLICY IF EXISTS flood_events_update ON flood_events;
ALTER TABLE flood_events DISABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS barangays_select ON barangays;
ALTER TABLE barangays DISABLE ROW LEVEL SECURITY;

DROP FUNCTION IF EXISTS app_user_id();
DROP FUNCTION IF EXISTS app_user_role();
DROP FUNCTION IF EXISTS app_user_barangay();
SQL);
    }
};
