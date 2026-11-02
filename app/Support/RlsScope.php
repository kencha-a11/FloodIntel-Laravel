<?php

namespace App\Support;

use Illuminate\Database\Connection;
use RuntimeException;

/**
 * The two-list row-level security scope (FR-64).
 *
 * Every table in the schema belongs to exactly one list. A table that appears
 * on neither list is a defect, and the RLS migration refuses to finish until
 * it is decided here.
 */
final class RlsScope
{
    /**
     * Tables with row-level security enabled and a declared policy set.
     *
     * @var list<string>
     */
    public const PROTECTED = [
        'access_tags',
        'barangays',
        'flood_events',
        'flood_impact_reports',
        'flood_reports',
        'flood_report_status_changes',
        'situational_reports',
        'situational_report_inclusions',
    ];

    /**
     * Tables with row-level security deliberately disabled.
     *
     * @var list<string>
     */
    public const UNPROTECTED = [
        // Framework state and infrastructure the runtime needs to start and operate.
        'migrations',
        'sessions',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        // Looked up before an authenticated user context exists (D-97).
        'users',
        'personal_access_tokens',
        'password_reset_tokens',
        // Operational telemetry with no tenant fields beyond a report id; the
        // rows are written by the measuring endpoint and read only by the
        // officer-gated latency summary (D-67).
        'realtime_latency_samples',
        // Login attempts are written before an authenticated identity exists:
        // a failed attempt has no user, so row-level security cannot key on
        // app.user_id. Append-only, with no read surface yet; AUTH-17 owns the
        // read and the protection decision when that surface lands.
        'login_events',
        // Created by the postgis extension, not by this application: a shared,
        // non-tenant SRID reference table (FR-72, D-99).
        'spatial_ref_sys',
    ];

    /**
     * Assert every table in the schema is on exactly one list with the right RLS state.
     */
    public static function assertEveryTableDecided(Connection $connection): void
    {
        $rows = $connection->select(<<<'SQL'
            SELECT c.relname AS name, c.relrowsecurity::text AS protected
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p')
        SQL);

        $undecided = [];
        $wrongState = [];

        foreach ($rows as $row) {
            $decided = in_array($row->name, self::PROTECTED, true)
                || in_array($row->name, self::UNPROTECTED, true);

            if (! $decided) {
                $undecided[] = $row->name;

                continue;
            }

            $expected = in_array($row->name, self::PROTECTED, true);
            $actual = strtolower((string) $row->protected) === 'true' || $row->protected === 't';

            if ($actual !== $expected) {
                $wrongState[] = sprintf(
                    '%s is on the %s list but row-level security is %s',
                    $row->name,
                    $expected ? 'protected' : 'unprotected',
                    $actual ? 'enabled' : 'disabled',
                );
            }
        }

        if ($undecided !== []) {
            throw new RuntimeException(sprintf(
                'RLS scope: %s %s on neither list; decide %s in App\Support\RlsScope in the same change (FR-64).',
                implode(', ', $undecided),
                count($undecided) === 1 ? 'is' : 'are',
                count($undecided) === 1 ? 'it' : 'them',
            ));
        }

        if ($wrongState !== []) {
            throw new RuntimeException('RLS scope: '.implode('; ', $wrongState).'.');
        }
    }
}
