<?php

namespace Tests\Postgres;

use App\Enums\FloodImpactStatus;
use App\Models\Barangay;
use App\Models\FloodEvent;
use App\Models\FloodImpactReport;
use App\Models\FloodReport;
use App\Models\User;
use App\Support\RlsScope;
use Closure;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

final class RowLevelSecurityTest extends PostgresTestCase
{
    use LazilyRefreshDatabase;

    private Barangay $barangayA;

    private Barangay $barangayB;

    private User $officer;

    private User $staff;

    private User $captainA;

    private User $tanodB;

    private User $rescueLeader;

    private FloodEvent $event;

    private FloodReport $reportA;

    private FloodReport $reportB;

    private FloodImpactReport $impactA;

    private FloodImpactReport $impactB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->barangayA = Barangay::factory()->create(['name' => 'San Juan', 'code' => 'SJ']);
        $this->barangayB = Barangay::factory()->create(['name' => 'San Pedro', 'code' => 'SP']);

        $this->officer = User::factory()->officer()->create();
        $this->staff = User::factory()->staff()->create();
        $this->captainA = User::factory()->captain()->forBarangay($this->barangayA)->create();
        $this->tanodB = User::factory()->tanod()->forBarangay($this->barangayB)->create();
        $this->rescueLeader = User::factory()->rescueLeader()->create();

        $this->event = FloodEvent::create(['title' => 'Typhoon', 'starts_at' => now()->subDay()]);

        $this->reportA = FloodReport::factory()->create([
            'user_id' => $this->captainA->id,
            'barangay_id' => $this->barangayA->id,
            'address' => null,
        ]);
        $this->reportB = FloodReport::factory()->create([
            'user_id' => $this->tanodB->id,
            'barangay_id' => $this->barangayB->id,
            'address' => 'Existing address',
        ]);

        $this->impactA = FloodImpactReport::create([
            'user_id' => $this->captainA->id,
            'barangay_id' => $this->barangayA->id,
            'event_id' => $this->event->id,
            'families' => 3,
            'individuals' => 12,
            'reported_at' => now(),
            'status' => FloodImpactStatus::Submitted,
        ]);
        $this->impactB = FloodImpactReport::create([
            'user_id' => $this->tanodB->id,
            'barangay_id' => $this->barangayB->id,
            'event_id' => $this->event->id,
            'families' => 1,
            'individuals' => 4,
            'reported_at' => now(),
            'status' => FloodImpactStatus::Submitted,
        ]);
    }

    public function test_the_declared_scope_matches_the_schema(): void
    {
        RlsScope::assertEveryTableDecided(DB::connection());

        foreach (RlsScope::PROTECTED as $table) {
            $this->assertSame('true', $this->rlsState($table), "{$table} must have RLS enabled.");
            $this->assertGreaterThan(0, $this->policyCount($table), "{$table} must declare a policy.");
        }

        foreach (RlsScope::UNPROTECTED as $table) {
            $this->assertSame('false', $this->rlsState($table), "{$table} must not have RLS enabled.");
            $this->assertSame(0, $this->policyCount($table), "{$table} must not declare a policy.");
        }
    }

    public function test_an_unset_identity_sees_no_rows_in_any_protected_table(): void
    {
        $this->asRuntime();

        try {
            foreach (RlsScope::PROTECTED as $table) {
                $this->assertSame(0, DB::table($table)->count(), "{$table} returned rows without an identity.");
            }
        } finally {
            $this->asOwner();
        }
    }

    public function test_an_unset_identity_cannot_insert_a_report(): void
    {
        $this->asRuntime();

        try {
            $this->assertPolicyViolation(fn () => DB::table('flood_reports')->insert(
                $this->reportRow($this->tanodB, $this->barangayB)
            ));
        } finally {
            $this->asOwner();
        }
    }

    public function test_a_barangay_user_reads_only_their_barangay(): void
    {
        $this->asRuntime($this->tanodB);

        try {
            $this->assertSame([$this->reportB->id], $this->integerIds('flood_reports'));
            $this->assertSame([$this->impactB->id], $this->integerIds('flood_impact_reports'));
            $this->assertSame(2, DB::table('barangays')->count());
            $this->assertSame(1, DB::table('flood_events')->count());
        } finally {
            $this->asOwner();
        }
    }

    public function test_a_command_center_user_reads_every_barangay(): void
    {
        foreach ([$this->officer, $this->staff] as $user) {
            $this->asRuntime($user);

            try {
                $this->assertSame(2, DB::table('flood_reports')->count());
                $this->assertSame(2, DB::table('flood_impact_reports')->count());
            } finally {
                $this->asOwner();
            }
        }
    }

    public function test_a_user_without_a_barangay_scope_reads_only_their_own_rows(): void
    {
        $own = FloodReport::factory()->create([
            'user_id' => $this->rescueLeader->id,
            'barangay_id' => $this->barangayA->id,
        ]);

        $this->asRuntime($this->rescueLeader);

        try {
            $this->assertSame([$own->id], $this->integerIds('flood_reports'));
        } finally {
            $this->asOwner();
        }
    }

    public function test_a_reporter_inserts_only_their_own_row_in_their_own_barangay(): void
    {
        $this->asRuntime($this->tanodB);

        try {
            DB::table('flood_reports')->insert($this->reportRow($this->tanodB, $this->barangayB));

            $this->assertPolicyViolation(fn () => DB::table('flood_reports')->insert(
                $this->reportRow($this->tanodB, $this->barangayA)
            ));

            $this->assertPolicyViolation(fn () => DB::table('flood_reports')->insert(
                $this->reportRow($this->captainA, $this->barangayB)
            ));

            $this->assertSame(2, DB::table('flood_reports')->count());
        } finally {
            $this->asOwner();
        }
    }

    public function test_only_command_center_roles_may_update_a_report(): void
    {
        $this->asRuntime($this->captainA);

        try {
            $this->assertSame(0, $this->updateStatus($this->reportA));
        } finally {
            $this->asOwner();
        }

        // D-23: a staff decision is a status write, so staff reaches this row.
        $this->asRuntime($this->staff);

        try {
            $this->assertSame(1, $this->updateStatus($this->reportA));
        } finally {
            $this->asOwner();
        }

        $this->asRuntime($this->officer);

        try {
            $this->assertSame(1, $this->updateStatus($this->reportA));
        } finally {
            $this->asOwner();
        }

        $this->assertSame('rejected', DB::table('flood_reports')->where('id', $this->reportA->id)->value('status'));
    }

    public function test_the_runtime_role_may_write_only_the_decision_columns_of_a_report(): void
    {
        // The factory's notes are optional, so compare with the stored value
        // rather than assuming the column is empty.
        $notes = DB::table('flood_reports')->where('id', $this->reportA->id)->value('notes');

        $this->asRuntime($this->officer);

        try {
            $this->assertPolicyViolation(fn () => DB::table('flood_reports')
                ->where('id', $this->reportA->id)
                ->update(['notes' => 'Rewritten by the runtime role.']));

            $this->assertSame(1, DB::table('flood_reports')
                ->where('id', $this->reportA->id)
                ->update(['rejection_reason' => 'Beacon reading is wrong.']));
        } finally {
            $this->asOwner();
        }

        $this->assertSame('Beacon reading is wrong.', DB::table('flood_reports')->where('id', $this->reportA->id)->value('rejection_reason'));
        $this->assertSame($notes, DB::table('flood_reports')->where('id', $this->reportA->id)->value('notes'));
    }

    public function test_no_runtime_role_may_delete_a_report(): void
    {
        $this->asRuntime($this->officer);

        try {
            $this->assertPolicyViolation(
                fn () => DB::table('flood_reports')->where('id', $this->reportA->id)->delete()
            );
        } finally {
            $this->asOwner();
        }
    }

    public function test_only_command_center_roles_may_write_events(): void
    {
        $this->asRuntime($this->tanodB);

        try {
            $this->assertPolicyViolation(fn () => DB::table('flood_events')->insert([
                'title' => 'Field event',
                'starts_at' => now(),
            ]));
        } finally {
            $this->asOwner();
        }

        $this->asRuntime($this->staff);

        try {
            DB::table('flood_events')->insert(['title' => 'Staff event', 'starts_at' => now()]);
        } finally {
            $this->asOwner();
        }

        $this->assertSame(2, DB::table('flood_events')->count());
    }

    public function test_the_owner_connection_bypasses_every_policy(): void
    {
        $this->assertSame(2, DB::table('flood_reports')->count());

        DB::table('flood_reports')->where('id', $this->reportA->id)->update(['notes' => 'Owner write']);

        $this->assertSame('Owner write', DB::table('flood_reports')->where('id', $this->reportA->id)->value('notes'));
    }

    public function test_the_maintenance_role_reaches_only_the_columns_and_rows_it_needs(): void
    {
        $clearable = FloodReport::factory()->create([
            'user_id' => $this->captainA->id,
            'barangay_id' => $this->barangayA->id,
            'address' => null,
        ]);

        $role = DB::selectOne(
            "select rolsuper, rolbypassrls from pg_roles where rolname = 'app_maintenance'"
        );

        $this->assertNotNull($role, 'The maintenance migration must create app_maintenance.');
        $this->assertFalse($role->rolsuper);
        $this->assertFalse($role->rolbypassrls);

        DB::statement('set local role app_maintenance');

        try {
            $this->assertSame(
                3,
                DB::table('flood_reports')->select('id', 'latitude', 'longitude', 'address')->count(),
                'The maintenance role reads its four columns from every report.'
            );

            $this->assertPolicyViolation(fn () => DB::table('flood_reports')->select('status')->first());

            $this->assertSame(1, DB::table('flood_reports')->where('id', $this->reportA->id)->update(['address' => 'Filled']));

            $this->assertSame(0, DB::table('flood_reports')->where('id', $this->reportB->id)->update(['address' => 'Overwrite']));

            $this->assertPolicyViolation(
                fn () => DB::table('flood_reports')->where('id', $clearable->id)->update(['address' => null])
            );

            $this->assertPolicyViolation(
                fn () => DB::table('flood_reports')->where('id', $clearable->id)->update(['status' => 'rejected'])
            );

            $this->assertPolicyViolation(fn () => DB::table('flood_reports')->insert(
                $this->reportRow($this->tanodB, $this->barangayB)
            ));
        } finally {
            $this->asOwner();
        }

        $this->assertSame('Filled', DB::table('flood_reports')->where('id', $this->reportA->id)->value('address'));
        $this->assertSame('Existing address', DB::table('flood_reports')->where('id', $this->reportB->id)->value('address'));
        $this->assertNull(DB::table('flood_reports')->where('id', $clearable->id)->value('address'));
    }

    public function test_the_runtime_role_may_assume_the_maintenance_role(): void
    {
        $this->assertTrue((bool) DB::scalar("select pg_has_role('app_runtime', 'app_maintenance', 'MEMBER')"));
        $this->assertFalse((bool) DB::scalar("select pg_has_role('app_maintenance', 'app_runtime', 'MEMBER')"));
    }

    public function test_route_model_binding_runs_under_the_request_identity(): void
    {
        $token = $this->tanodB->createToken('rls')->plainTextToken;

        DB::statement('set local role app_runtime');

        try {
            $this->withToken($token)
                ->getJson("/api/flood-reports/{$this->reportB->id}")
                ->assertOk()
                ->assertJsonPath('data.id', $this->reportB->id);

            $this->withToken($token)
                ->getJson("/api/flood-reports/{$this->reportA->id}")
                ->assertNotFound();

            $this->withToken($token)
                ->getJson('/api/flood-reports')
                ->assertOk()
                ->assertJsonCount(1, 'data');

            $this->assertSame(
                '',
                (string) DB::scalar("select coalesce(current_setting('app.user_id', true), '')"),
                'The request identity must not outlive the request.'
            );
        } finally {
            DB::statement('reset role');
        }
    }

    public function test_a_submission_inserts_and_its_address_fills_under_the_runtime_role(): void
    {
        Storage::fake('public');
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response(['display_name' => 'Mabini Street, Apalit']),
        ]);
        config(['services.geocoder.min_interval_ms' => 0]);

        $token = $this->tanodB->createToken('rls')->plainTextToken;

        DB::statement('set local role app_runtime');

        try {
            $this->withToken($token)
                ->post('/api/flood-reports', [
                    'flood_level' => 'knee',
                    'latitude' => 14.9501,
                    'longitude' => 120.7501,
                    'gps_accuracy' => 12.5,
                    'photo' => testPhoto(),
                    'notes' => 'Water rising near the school.',
                    'reported_at' => now()->subMinutes(5)->toIso8601String(),
                ])
                ->assertCreated()
                ->assertJsonPath('data.barangay.id', $this->barangayB->id);
        } finally {
            DB::statement('reset role');
        }

        $this->assertSame(
            'Mabini Street, Apalit',
            (string) DB::table('flood_reports')->where('user_id', $this->tanodB->id)->where('id', '>', $this->reportB->id)->value('address')
        );
    }

    public function test_login_returns_the_barangay_while_the_runtime_role_is_active(): void
    {
        $user = User::factory()->captain()->forBarangay($this->barangayA)->create();

        DB::statement('set local role app_runtime');

        try {
            $this->postJson('/api/login', [
                'login' => $user->email,
                'password' => 'password',
            ])
                ->assertOk()
                ->assertJsonPath('user.barangay.id', $this->barangayA->id);
        } finally {
            DB::statement('reset role');
        }
    }

    public function test_the_database_queue_round_trips_while_rls_is_enabled(): void
    {
        config(['queue.default' => 'database']);
        ProbeJob::$handled = 0;

        DB::statement('set local role app_runtime');

        try {
            ProbeJob::dispatch();

            $this->assertSame(1, DB::table('jobs')->count(), 'The runtime role must be able to push a job.');

            $this->artisan('queue:work', ['--once' => true, '--tries' => 1])->assertSuccessful();

            $this->assertSame(1, ProbeJob::$handled);
            $this->assertSame(0, DB::table('jobs')->count(), 'The worker must be able to clear the job.');
        } finally {
            DB::statement('reset role');
        }
    }

    public function test_a_rate_limited_route_still_throttles_while_rls_is_enabled(): void
    {
        config(['cache.default' => 'database']);
        Cache::flush();

        DB::statement('set local role app_runtime');

        try {
            for ($attempt = 1; $attempt <= 20; $attempt++) {
                $this->postJson('/api/login', [
                    'login' => "nobody{$attempt}@example.com",
                    'password' => 'wrong-password',
                ])->assertStatus(422);
            }

            $this->postJson('/api/login', [
                'login' => 'nobody21@example.com',
                'password' => 'wrong-password',
            ])->assertStatus(429);
        } finally {
            DB::statement('reset role');
            Cache::flush();
        }
    }

    /**
     * A table the scope has never seen stops the guard.
     */
    public function test_the_guard_refuses_an_undecided_table(): void
    {
        DB::statement('create table undecided_probe (id serial primary key)');

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('undecided_probe');

            RlsScope::assertEveryTableDecided(DB::connection());
        } finally {
            DB::statement('drop table undecided_probe');
        }
    }

    /**
     * A declared table whose RLS state drifted stops the guard.
     */
    public function test_the_guard_refuses_a_drifted_rls_state(): void
    {
        DB::statement('alter table flood_events disable row level security');

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('flood_events');

            RlsScope::assertEveryTableDecided(DB::connection());
        } finally {
            DB::statement('alter table flood_events enable row level security');
        }
    }

    private function asRuntime(?User $user = null): void
    {
        DB::statement('set local role app_runtime');
        $this->identify($user);
    }

    private function asOwner(): void
    {
        DB::statement('reset role');
        $this->identify(null);
    }

    private function identify(?User $user): void
    {
        DB::statement("select set_config('app.user_id', ?, true)", [(string) ($user?->getKey() ?? '')]);
    }

    /**
     * @return array<string, mixed>
     */
    private function reportRow(User $user, Barangay $barangay): array
    {
        return [
            'user_id' => $user->id,
            'barangay_id' => $barangay->id,
            'flood_level' => 'knee',
            'latitude' => 14.9501,
            'longitude' => 120.7501,
            'photo_path' => 'flood-reports/probe.jpg',
            'reported_at' => now(),
            'status' => 'submitted',
        ];
    }

    private function updateStatus(FloodReport $report): int
    {
        return DB::table('flood_reports')->where('id', $report->id)->update(['status' => 'rejected']);
    }

    /**
     * @return list<int>
     */
    private function integerIds(string $table): array
    {
        return array_map('intval', DB::table($table)->orderBy('id')->pluck('id')->all());
    }

    private function assertPolicyViolation(Closure $callback): void
    {
        try {
            // A savepoint keeps the test's transaction usable after the error.
            DB::transaction($callback);
            $this->fail('The statement was expected to violate row-level security.');
        } catch (QueryException $exception) {
            $this->assertSame('42501', $exception->getCode(), $exception->getMessage());
        }
    }

    private function rlsState(string $table): string
    {
        return (string) DB::scalar(
            "select relrowsecurity::text from pg_class where relname = ? and relnamespace = 'public'::regnamespace",
            [$table]
        );
    }

    private function policyCount(string $table): int
    {
        return (int) DB::scalar(
            'select count(*) from pg_policies where schemaname = ? and tablename = ?',
            ['public', $table]
        );
    }
}

final class ProbeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public static int $handled = 0;

    public function handle(): void
    {
        self::$handled++;
    }
}
