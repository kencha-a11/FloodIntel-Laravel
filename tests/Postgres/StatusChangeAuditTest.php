<?php

namespace Tests\Postgres;

use App\Models\Barangay;
use App\Models\FloodReport;
use App\Models\FloodReportStatusChange;
use App\Models\User;
use App\Services\FloodReportTransitions;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

final class StatusChangeAuditTest extends PostgresTestCase
{
    use LazilyRefreshDatabase;

    private Barangay $barangayA;

    private Barangay $barangayB;

    private User $officer;

    private User $staff;

    private User $tanodA;

    private User $tanodB;

    private FloodReport $reportA;

    private FloodReport $reportB;

    private FloodReportStatusChange $entryA;

    private FloodReportStatusChange $entryB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->barangayA = Barangay::factory()->create(['name' => 'San Juan', 'code' => 'SJ']);
        $this->barangayB = Barangay::factory()->create(['name' => 'San Pedro', 'code' => 'SP']);

        $this->officer = User::factory()->officer()->create();
        $this->staff = User::factory()->staff()->create();
        $this->tanodA = User::factory()->tanod()->forBarangay($this->barangayA)->create();
        $this->tanodB = User::factory()->tanod()->forBarangay($this->barangayB)->create();

        $this->reportA = FloodReport::factory()->create([
            'user_id' => $this->tanodA->id,
            'barangay_id' => $this->barangayA->id,
        ]);
        $this->reportB = FloodReport::factory()->create([
            'user_id' => $this->tanodB->id,
            'barangay_id' => $this->barangayB->id,
        ]);

        $transitions = app(FloodReportTransitions::class);

        $this->entryA = $transitions->submit($this->reportA, $this->tanodA);
        $this->entryB = $transitions->submit($this->reportB, $this->tanodB);
    }

    public function test_the_runtime_role_may_select_and_insert_but_never_update_or_delete(): void
    {
        $this->assertTrue($this->hasTablePrivilege('SELECT'));
        $this->assertTrue($this->hasTablePrivilege('INSERT'));
        $this->assertFalse($this->hasTablePrivilege('UPDATE'));
        $this->assertFalse($this->hasTablePrivilege('DELETE'));

        $this->asRuntime($this->officer);

        try {
            $this->assertDenied(fn () => DB::table('flood_report_status_changes')
                ->where('id', $this->entryA->id)
                ->update(['reason' => 'Rewritten.']));

            $this->assertDenied(fn () => DB::table('flood_report_status_changes')
                ->where('id', $this->entryA->id)
                ->delete());
        } finally {
            $this->asOwner();
        }

        $this->assertSame(2, DB::table('flood_report_status_changes')->count());
        $this->assertNull(DB::table('flood_report_status_changes')->where('id', $this->entryA->id)->value('reason'));
    }

    public function test_the_select_policy_admits_only_audit_rows_of_visible_reports(): void
    {
        $this->asRuntime();

        try {
            $this->assertSame(0, DB::table('flood_report_status_changes')->count());
        } finally {
            $this->asOwner();
        }

        $this->asRuntime($this->tanodB);

        try {
            $this->assertSame([$this->entryB->id], $this->entryIds());
        } finally {
            $this->asOwner();
        }

        $this->asRuntime($this->officer);

        try {
            $this->assertSame(2, DB::table('flood_report_status_changes')->count());
        } finally {
            $this->asOwner();
        }
    }

    public function test_the_insert_policy_requires_the_actor_identity(): void
    {
        $this->asRuntime($this->tanodB);

        try {
            $this->assertDenied(fn () => DB::table('flood_report_status_changes')->insert(
                $this->entryRow($this->reportB, $this->tanodA),
            ));

            DB::table('flood_report_status_changes')->insert($this->entryRow($this->reportB, $this->tanodB));
        } finally {
            $this->asOwner();
        }

        $this->assertSame(3, DB::table('flood_report_status_changes')->count());
    }

    public function test_a_staff_decision_writes_the_status_and_the_audit_row_under_rls(): void
    {
        DB::statement('set local role app_runtime');
        $this->identify($this->staff);

        try {
            app(FloodReportTransitions::class)->return($this->reportB, $this->staff, 'Photo is too blurry.');

            $this->assertSame(
                ['returned'],
                DB::table('flood_reports')->where('id', $this->reportB->id)->pluck('status')->all(),
            );
        } finally {
            $this->asOwner();
        }

        $entry = DB::table('flood_report_status_changes')
            ->where('flood_report_id', $this->reportB->id)
            ->where('to_status', 'returned')
            ->sole();

        $this->assertSame($this->staff->id, (int) $entry->user_id);
        $this->assertSame('Photo is too blurry.', $entry->reason);
        $this->assertSame('submitted', $entry->from_status);
    }

    /**
     * @return list<int>
     */
    private function entryIds(): array
    {
        return array_map(
            static fn (object $row): int => (int) $row->id,
            DB::table('flood_report_status_changes')->orderBy('id')->get()->all(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function entryRow(FloodReport $report, User $actor): array
    {
        return [
            'flood_report_id' => $report->id,
            'user_id' => $actor->id,
            'actor_name' => $actor->full_name,
            'actor_role' => $actor->role->value,
            'from_status' => 'submitted',
            'to_status' => 'rejected',
            'reason' => 'Probe.',
        ];
    }

    private function hasTablePrivilege(string $privilege): bool
    {
        return (bool) DB::scalar(
            'select has_table_privilege(?, ?, ?)',
            ['app_runtime', 'flood_report_status_changes', $privilege],
        );
    }

    private function assertDenied(Closure $write): void
    {
        try {
            // A savepoint keeps the test's transaction usable after the error.
            DB::transaction($write);
            $this->fail('The runtime role wrote the append-only audit table.');
        } catch (QueryException $exception) {
            $this->assertSame('42501', $exception->getCode(), $exception->getMessage());
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
}
