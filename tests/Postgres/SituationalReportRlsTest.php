<?php

namespace Tests\Postgres;

use App\Enums\FloodReportStatus;
use App\Models\Barangay;
use App\Models\FloodReport;
use App\Models\FloodReportStatusChange;
use App\Models\User;
use App\Services\SituationalReportAggregator;
use App\Support\ConsolidationWindow;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class SituationalReportRlsTest extends PostgresTestCase
{
    use LazilyRefreshDatabase;

    private Barangay $barangay;

    private User $staff;

    private User $officer;

    private User $tanod;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-02 10:00:00', 'Asia/Manila'));

        $this->barangay = Barangay::factory()->create(['name' => 'Balucuc', 'code' => 'PH0305402001']);
        $this->staff = User::factory()->staff()->create();
        $this->officer = User::factory()->officer()->create();
        $this->tanod = User::factory()->tanod()->forBarangay($this->barangay)->create();

        $report = FloodReport::factory()->create([
            'user_id' => $this->tanod->id,
            'barangay_id' => $this->barangay->id,
            'status' => FloodReportStatus::Validated,
            'reported_at' => now()->subMinutes(30),
            'created_at' => now()->subMinutes(25),
            'updated_at' => now()->subMinutes(25),
        ]);

        FloodReportStatusChange::create([
            'flood_report_id' => $report->id,
            'user_id' => $this->staff->id,
            'actor_name' => $this->staff->full_name,
            'actor_role' => $this->staff->role->value,
            'from_status' => FloodReportStatus::Submitted,
            'to_status' => FloodReportStatus::Validated,
        ]);
    }

    public function test_the_runtime_role_may_select_and_insert_but_never_update_or_delete(): void
    {
        foreach (['situational_reports', 'situational_report_inclusions'] as $table) {
            $this->assertTrue($this->hasTablePrivilege($table, 'SELECT'));
            $this->assertTrue($this->hasTablePrivilege($table, 'INSERT'));
            $this->assertFalse($this->hasTablePrivilege($table, 'UPDATE'));
            $this->assertFalse($this->hasTablePrivilege($table, 'DELETE'));
        }
    }

    public function test_staff_can_prepare_a_snapshot_under_rls(): void
    {
        $this->asRuntime($this->staff);

        try {
            $report = app(SituationalReportAggregator::class)->prepare(ConsolidationWindow::current(), $this->staff);

            $this->assertSame(1, $report->inclusions->count());
        } finally {
            $this->asOwner();
        }

        $this->assertSame(1, DB::table('situational_reports')->count());
        $this->assertSame(1, DB::table('situational_report_inclusions')->count());
        $this->assertSame(1, DB::table('situational_reports')->value('revision'));
    }

    public function test_a_field_role_cannot_read_or_insert_a_revision(): void
    {
        $this->asRuntime($this->staff);

        try {
            app(SituationalReportAggregator::class)->prepare(ConsolidationWindow::current(), $this->staff);
        } finally {
            $this->asOwner();
        }

        $this->asRuntime($this->tanod);

        try {
            $this->assertSame(0, DB::table('situational_reports')->count());
            $this->assertSame(0, DB::table('situational_report_inclusions')->count());
            $this->assertDenied(fn () => DB::table('situational_reports')->insert($this->snapshotRow($this->tanod)));
        } finally {
            $this->asOwner();
        }
    }

    public function test_the_insert_policy_requires_the_generator_identity(): void
    {
        $this->asRuntime($this->officer);

        try {
            $this->assertDenied(fn () => DB::table('situational_reports')->insert($this->snapshotRow($this->staff)));
            $this->assertTrue(DB::table('situational_reports')->insert($this->snapshotRow($this->officer)));
        } finally {
            $this->asOwner();
        }

        $this->assertSame(1, DB::table('situational_reports')->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotRow(User $generator): array
    {
        return [
            'window_starts_at' => now()->startOfDay()->addHours(4),
            'window_ends_at' => now(),
            'cutoff_at' => now(),
            'cutoff_label' => '4:00 AM – 3:00 PM',
            'timezone' => 'Asia/Manila',
            'snapshot_at' => now(),
            'revision' => 1,
            'format_version' => '1',
            'generated_by_user_id' => $generator->id,
            'generated_by_name' => $generator->full_name,
            'generated_by_role' => $generator->role->value,
            'status_counts' => json_encode(['basis' => 'reported_at', 'validated' => 0, 'pending' => 0, 'returned' => 0, 'rejected' => 0]),
            'totals' => json_encode(['reports' => 0, 'included_reports' => 0, 'alert_level' => null, 'flood_level' => null]),
            'barangays' => json_encode([]),
            'latency' => json_encode(['samples' => 0, 'excluded_null_observation' => 0, 'mean_seconds' => null, 'median_seconds' => null, 'max_seconds' => null]),
            'stage_two' => json_encode(['available' => false, 'reason' => 'Not available yet.', 'affected_families' => null, 'affected_individuals' => null]),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function hasTablePrivilege(string $table, string $privilege): bool
    {
        return (bool) DB::scalar(
            'select has_table_privilege(?, ?, ?)',
            ['app_runtime', $table, $privilege],
        );
    }

    private function assertDenied(Closure $write): void
    {
        try {
            // A savepoint keeps the test's transaction usable after the error.
            DB::transaction($write);
            $this->fail('The statement was expected to violate row-level security.');
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
