<?php

namespace Tests\Postgres;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class PostgresPathTest extends PostgresTestCase
{
    use LazilyRefreshDatabase;

    public function test_the_application_migrations_run_on_postgres(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertTrue(Schema::hasTable('migrations'));
        $this->assertGreaterThan(0, DB::table('migrations')->count());
        $this->assertTrue(Schema::hasTable('barangays'));
        $this->assertTrue(Schema::hasTable('flood_reports'));
    }

    public function test_a_check_constraint_is_enforced_by_the_database(): void
    {
        DB::statement(
            "create table postgres_path_check (id serial primary key, level text not null check (level in ('minor', 'major')))"
        );

        DB::table('postgres_path_check')->insert(['level' => 'minor']);

        try {
            DB::table('postgres_path_check')->insert(['level' => 'severe']);
            $this->fail('The CHECK constraint did not reject an invalid value.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->getCode());
        }
    }

    public function test_a_row_level_security_policy_applies_to_a_non_owner_role(): void
    {
        DB::statement('create table postgres_path_reports (id serial primary key, barangay text not null)');
        DB::table('postgres_path_reports')->insert([['barangay' => 'A'], ['barangay' => 'B']]);

        DB::statement('drop role if exists floodintel_probe');
        DB::statement('create role floodintel_probe nologin');
        DB::statement('grant floodintel_probe to current_user');
        DB::statement('grant usage on schema public to floodintel_probe');
        DB::statement('grant select on postgres_path_reports to floodintel_probe');
        DB::statement('alter table postgres_path_reports enable row level security');
        DB::statement(
            "create policy postgres_path_reports_select on postgres_path_reports for select to floodintel_probe
             using (barangay = current_setting('app.probe_barangay', true))"
        );

        $this->assertSame(2, DB::table('postgres_path_reports')->count(), 'The owner bypass is expected and documented (D-08, D-61).');

        DB::statement('set local role floodintel_probe');

        DB::statement("set local app.probe_barangay = 'A'");
        $this->assertSame(['A'], DB::table('postgres_path_reports')->pluck('barangay')->all());

        DB::statement("set local app.probe_barangay = 'B'");
        $this->assertSame(['B'], DB::table('postgres_path_reports')->pluck('barangay')->all());

        DB::statement('reset app.probe_barangay');
        $this->assertSame(0, DB::table('postgres_path_reports')->count(), 'An unset identity setting must deny every row.');

        DB::statement('reset role');
    }
}
