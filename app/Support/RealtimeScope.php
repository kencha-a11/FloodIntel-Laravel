<?php

namespace App\Support;

use Illuminate\Database\Connection;

/**
 * The database side of private-channel authorization (FR-68, D-65).
 *
 * The decision lives in one function so the Supabase realtime.messages policy
 * and the tests read the same rules as FloodReport::visibleTo(). Divergence
 * from Supabase: this application's users have bigint ids, so the policy reads
 * the JWT `sub` claim as a bigint instead of auth.uid()'s uuid.
 */
final class RealtimeScope
{
    public static function install(Connection $connection): void
    {
        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $connection->unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION app_realtime_can_receive(p_user_id bigint, p_topic text) RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
  SELECT EXISTS (
    SELECT 1 FROM users u
    WHERE u.id = p_user_id
      AND u.email_verified_at IS NOT NULL
      AND (
        (u.role IN ('mdrrmo_officer', 'mdrrmo_staff') AND p_topic = 'reports:all')
        OR (u.role IN ('barangay_captain', 'barangay_tanod')
            AND u.barangay_id IS NOT NULL
            AND p_topic = 'reports:barangay:' || u.barangay_id::text)
        OR p_topic = 'reports:user:' || u.id::text
      )
  )
$$;

REVOKE ALL ON FUNCTION app_realtime_can_receive(bigint, text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION app_realtime_can_receive(bigint, text) TO app_runtime;

DO $$
BEGIN
  IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'authenticated') THEN
    EXECUTE 'GRANT EXECUTE ON FUNCTION app_realtime_can_receive(bigint, text) TO authenticated';
  END IF;
END
$$;
SQL);

        if ($connection->scalar("select to_regclass('realtime.messages')") === null) {
            return;
        }

        $connection->unprepared(<<<'SQL'
DROP POLICY IF EXISTS app_realtime_receive ON realtime.messages;

CREATE POLICY app_realtime_receive ON realtime.messages
FOR SELECT TO authenticated
USING (
  realtime.messages.extension = 'broadcast'
  AND app_realtime_can_receive(
    nullif(auth.jwt()->>'sub', '')::bigint,
    realtime.messages.topic
  )
);
SQL);
    }
}
