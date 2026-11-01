<?php

namespace Tests\Postgres;

use App\Enums\FloodImpactStatus;
use App\Models\Barangay;
use App\Models\FloodEvent;
use App\Models\FloodImpactReport;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

final class FloodImpactReportIdempotencyTest extends PostgresTestCase
{
    use LazilyRefreshDatabase;

    private Barangay $barangay;

    private FloodEvent $event;

    private User $captain;

    private User $otherCaptain;

    protected function setUp(): void
    {
        parent::setUp();

        $this->barangay = Barangay::factory()->create(['name' => 'San Juan', 'code' => 'SJ']);
        $otherBarangay = Barangay::factory()->create(['name' => 'San Pedro', 'code' => 'SP']);

        $this->event = FloodEvent::factory()->create();
        $this->captain = User::factory()->captain()->forBarangay($this->barangay)->create();
        $this->otherCaptain = User::factory()->captain()->forBarangay($otherBarangay)->create();
    }

    public function test_the_unique_index_refuses_a_replayed_key_for_one_reporter(): void
    {
        $this->report($this->captain, 'replayed');

        try {
            // A savepoint keeps the test's transaction usable after the error.
            DB::transaction(fn () => $this->report($this->captain, 'replayed'));
            $this->fail('The unique index accepted a replayed key for one reporter.');
        } catch (QueryException $exception) {
            $this->assertSame('23505', $exception->getCode(), $exception->getMessage());
        }

        $this->assertSame(1, FloodImpactReport::where('client_uuid', 'replayed')->count());
    }

    public function test_a_missing_key_or_another_reporter_is_unrestricted(): void
    {
        $this->report($this->captain, null);
        $this->report($this->captain, null);
        $this->report($this->captain, 'shared');
        $this->report($this->otherCaptain, 'shared');

        $this->assertSame(4, FloodImpactReport::count());
        $this->assertSame(2, FloodImpactReport::where('client_uuid', 'shared')->count());
    }

    public function test_the_runtime_role_writes_the_key_and_the_index_refuses_a_replay(): void
    {
        $this->asRuntime($this->captain);

        try {
            DB::table('flood_impact_reports')->insert($this->row('runtime-key'));

            try {
                // A savepoint keeps the test's transaction usable after the error.
                DB::transaction(fn () => DB::table('flood_impact_reports')->insert($this->row('runtime-key')));
                $this->fail('The runtime role replayed a key into a second row.');
            } catch (QueryException $exception) {
                $this->assertSame('23505', $exception->getCode(), $exception->getMessage());
            }
        } finally {
            $this->asOwner();
        }

        $this->assertSame(1, DB::table('flood_impact_reports')->where('client_uuid', 'runtime-key')->count());
    }

    private function report(User $user, ?string $clientUuid): FloodImpactReport
    {
        return FloodImpactReport::factory()->create([
            'user_id' => $user->id,
            'barangay_id' => $user->barangay_id,
            'event_id' => $this->event->id,
            'client_uuid' => $clientUuid,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $clientUuid): array
    {
        return [
            'user_id' => $this->captain->id,
            'barangay_id' => $this->barangay->id,
            'event_id' => $this->event->id,
            'families' => 12,
            'individuals' => 48,
            'reported_at' => now(),
            'status' => FloodImpactStatus::Submitted->value,
            'client_uuid' => $clientUuid,
            'created_at' => now(),
            'updated_at' => now(),
        ];
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
