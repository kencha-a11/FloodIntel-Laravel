<?php

namespace Tests\Postgres;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

final class RuntimeRoleTest extends PostgresTestCase
{
    use LazilyRefreshDatabase;

    /**
     * Tables app_runtime is explicitly granted on. Every public table must be
     * on this list or the ungranted list, so a new table fails this test until
     * someone decides its grants (CASE-009: realtime_latency_samples was
     * created without one and the fixed assertions never noticed).
     *
     * @var list<string>
     */
    private const GRANTED_TABLES = [
        'access_tags',
        'barangays',
        'cache',
        'cache_locks',
        'failed_jobs',
        'flood_events',
        'flood_impact_reports',
        'flood_report_status_changes',
        'flood_reports',
        'job_batches',
        'jobs',
        'login_events',
        'password_reset_tokens',
        'personal_access_tokens',
        'realtime_latency_samples',
        'sessions',
        'situational_report_inclusions',
        'situational_reports',
        'users',
    ];

    /**
     * Tables the runtime is deliberately not granted on.
     *
     * @var list<string>
     */
    private const UNGRANTED_TABLES = [
        'migrations',
        'spatial_ref_sys',
    ];

    public function test_every_public_table_has_a_declared_grant_decision(): void
    {
        $tables = array_map(
            fn (object $row): string => $row->name,
            DB::select(<<<'SQL'
                SELECT c.relname AS name
                FROM pg_class c
                JOIN pg_namespace n ON n.oid = c.relnamespace
                WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p')
            SQL),
        );

        $this->assertSame([], array_values(array_intersect(self::GRANTED_TABLES, self::UNGRANTED_TABLES)));

        $undecided = array_values(array_diff($tables, self::GRANTED_TABLES, self::UNGRANTED_TABLES));

        $this->assertSame(
            [],
            $undecided,
            'Decide app_runtime grants for: '.implode(', ', $undecided),
        );
    }

    public function test_every_granted_table_is_readable_by_the_runtime_role(): void
    {
        foreach (self::GRANTED_TABLES as $table) {
            $this->assertTrue(
                $this->hasTablePrivilege($table, 'SELECT'),
                "app_runtime must be granted SELECT on {$table}.",
            );
        }
    }

    public function test_the_runtime_role_can_write_latency_samples(): void
    {
        $this->assertTrue($this->hasTablePrivilege('realtime_latency_samples', 'SELECT'));
        $this->assertTrue($this->hasTablePrivilege('realtime_latency_samples', 'INSERT'));
        $this->assertFalse($this->hasTablePrivilege('realtime_latency_samples', 'UPDATE'));
        $this->assertTrue($this->hasSequencePrivilege('realtime_latency_samples_id_seq', 'USAGE'));
    }

    public function test_the_runtime_role_is_not_the_owner(): void
    {
        $role = DB::selectOne(
            "select rolsuper, rolbypassrls from pg_roles where rolname = 'app_runtime'"
        );

        $this->assertNotNull($role, 'The provision migration must create app_runtime.');
        $this->assertFalse($role->rolsuper);
        $this->assertFalse($role->rolbypassrls);
    }

    public function test_the_runtime_role_has_only_the_declared_table_privileges(): void
    {
        $this->assertTrue($this->hasTablePrivilege('flood_reports', 'SELECT'));
        $this->assertTrue($this->hasTablePrivilege('flood_reports', 'INSERT'));
        $this->assertFalse($this->hasTablePrivilege('flood_reports', 'UPDATE'), 'UPDATE is granted per decision column, not per table.');
        $this->assertFalse($this->hasTablePrivilege('flood_reports', 'DELETE'));

        foreach (['status', 'rejected_at', 'rejected_by', 'rejection_reason', 'updated_at'] as $column) {
            $this->assertTrue($this->hasColumnPrivilege('flood_reports', $column, 'UPDATE'), "{$column} must be writable.");
        }

        $this->assertFalse($this->hasColumnPrivilege('flood_reports', 'notes', 'UPDATE'));
        $this->assertFalse($this->hasColumnPrivilege('flood_reports', 'photo_path', 'UPDATE'));

        $this->assertTrue($this->hasTablePrivilege('flood_impact_reports', 'INSERT'));
        $this->assertFalse($this->hasTablePrivilege('flood_impact_reports', 'UPDATE'));

        $this->assertTrue($this->hasTablePrivilege('flood_events', 'UPDATE'));

        $this->assertTrue($this->hasTablePrivilege('barangays', 'SELECT'));
        $this->assertFalse($this->hasTablePrivilege('barangays', 'INSERT'));

        $this->assertTrue($this->hasTablePrivilege('users', 'DELETE'));

        $this->assertFalse($this->hasTablePrivilege('migrations', 'SELECT'));
    }

    public function test_the_runtime_role_has_sequence_usage(): void
    {
        $allowed = DB::selectOne(
            "select has_sequence_privilege('app_runtime', 'flood_reports_id_seq', 'USAGE') as allowed"
        );

        $this->assertTrue($allowed->allowed);
    }

    public function test_the_runtime_role_reads_a_granted_table_as_a_non_owner(): void
    {
        DB::statement('set local role app_runtime');

        $this->assertSame(0, DB::table('barangays')->count());

        DB::statement('reset role');
    }

    public function test_the_runtime_role_cannot_read_an_ungranted_table(): void
    {
        DB::statement('create table runtime_role_probe (id serial primary key)');
        DB::statement('set local role app_runtime');

        try {
            DB::table('runtime_role_probe')->count();
            $this->fail('The runtime role read a table it was not granted.');
        } catch (QueryException $exception) {
            $this->assertSame('42501', $exception->getCode());
        }
    }

    public function test_the_runtime_role_cannot_read_the_migrations_table(): void
    {
        DB::statement('set local role app_runtime');

        try {
            DB::table('migrations')->count();
            $this->fail('The runtime role read the migrations table.');
        } catch (QueryException $exception) {
            $this->assertSame('42501', $exception->getCode());
        }
    }

    public function test_the_runtime_role_cannot_create_tables(): void
    {
        DB::statement('set local role app_runtime');

        try {
            DB::statement('create table runtime_role_ddl (id integer)');
            $this->fail('The runtime role was able to create a table.');
        } catch (QueryException $exception) {
            $this->assertSame('42501', $exception->getCode());
        }
    }

    private function hasTablePrivilege(string $table, string $privilege): bool
    {
        $row = DB::selectOne(
            'select has_table_privilege(cast(? as name), ?, ?) as allowed',
            ['app_runtime', $table, $privilege]
        );

        return $row->allowed;
    }

    private function hasColumnPrivilege(string $table, string $column, string $privilege): bool
    {
        $row = DB::selectOne(
            'select has_column_privilege(cast(? as name), ?, ?, ?) as allowed',
            ['app_runtime', $table, $column, $privilege]
        );

        return $row->allowed;
    }

    private function hasSequencePrivilege(string $sequence, string $privilege): bool
    {
        $row = DB::selectOne(
            'select has_sequence_privilege(cast(? as name), ?, ?) as allowed',
            ['app_runtime', $sequence, $privilege]
        );

        return $row->allowed;
    }
}
