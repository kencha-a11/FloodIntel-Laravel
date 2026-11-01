<?php

namespace Tests\Postgres;

use App\Enums\FloodLevel;
use App\Enums\FloodReportStatus;
use App\Models\Barangay;
use App\Models\FloodReport;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

final class FloodReportIdempotencyTest extends PostgresTestCase
{
    use LazilyRefreshDatabase;

    private Barangay $barangay;

    private User $tanod;

    private User $otherTanod;

    protected function setUp(): void
    {
        parent::setUp();

        $this->barangay = Barangay::factory()->create(['name' => 'San Juan', 'code' => 'SJ']);
        $otherBarangay = Barangay::factory()->create(['name' => 'San Pedro', 'code' => 'SP']);

        $this->tanod = User::factory()->tanod()->forBarangay($this->barangay)->create();
        $this->otherTanod = User::factory()->tanod()->forBarangay($otherBarangay)->create();
    }

    public function test_the_unique_index_refuses_a_replayed_key_for_one_reporter(): void
    {
        $this->report($this->tanod, 'replayed');

        try {
            // A savepoint keeps the test's transaction usable after the error.
            DB::transaction(fn () => $this->report($this->tanod, 'replayed'));
            $this->fail('The unique index accepted a replayed key for one reporter.');
        } catch (QueryException $exception) {
            $this->assertSame('23505', $exception->getCode(), $exception->getMessage());
        }

        $this->assertSame(1, FloodReport::where('client_uuid', 'replayed')->count());
    }

    public function test_a_missing_key_or_another_reporter_is_unrestricted(): void
    {
        $this->report($this->tanod, null);
        $this->report($this->tanod, null);
        $this->report($this->tanod, 'shared');
        $this->report($this->otherTanod, 'shared');

        $this->assertSame(4, FloodReport::count());
        $this->assertSame(2, FloodReport::where('client_uuid', 'shared')->count());
    }

    public function test_the_runtime_role_writes_the_key_and_the_index_refuses_a_replay(): void
    {
        $this->asRuntime($this->tanod);

        try {
            DB::table('flood_reports')->insert($this->row('runtime-key'));

            try {
                // A savepoint keeps the test's transaction usable after the error.
                DB::transaction(fn () => DB::table('flood_reports')->insert($this->row('runtime-key')));
                $this->fail('The runtime role replayed a key into a second row.');
            } catch (QueryException $exception) {
                $this->assertSame('23505', $exception->getCode(), $exception->getMessage());
            }
        } finally {
            $this->asOwner();
        }

        $this->assertSame(1, DB::table('flood_reports')->where('client_uuid', 'runtime-key')->count());
    }

    private function report(User $user, ?string $clientUuid): FloodReport
    {
        return FloodReport::factory()->create([
            'user_id' => $user->id,
            'barangay_id' => $user->barangay_id,
            'client_uuid' => $clientUuid,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $clientUuid): array
    {
        return [
            'user_id' => $this->tanod->id,
            'barangay_id' => $this->barangay->id,
            'flood_level' => FloodLevel::Knee->value,
            'latitude' => 14.5995,
            'longitude' => 120.9842,
            'photo_path' => 'flood-reports/'.$this->tanod->id.'/2026-11/test.jpg',
            'reported_at' => now(),
            'status' => FloodReportStatus::Submitted->value,
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
