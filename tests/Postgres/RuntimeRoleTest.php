<?php

namespace Tests\Postgres;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

final class RuntimeRoleTest extends PostgresTestCase
{
    use LazilyRefreshDatabase;

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
}
