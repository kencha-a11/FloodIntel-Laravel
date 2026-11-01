<?php

namespace Tests\Postgres;

use App\Models\AccessTag;
use App\Models\Barangay;
use App\Models\User;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

final class AccessTagRetirementTest extends PostgresTestCase
{
    use LazilyRefreshDatabase;

    private Barangay $barangay;

    private User $recorder;

    private User $officer;

    private AccessTag $tag;

    protected function setUp(): void
    {
        parent::setUp();

        $this->barangay = Barangay::factory()->create();
        $this->recorder = User::factory()->tanod()->forBarangay($this->barangay)->create();
        $this->officer = User::factory()->officer()->create();
        $this->tag = AccessTag::factory()->create([
            'barangay_id' => $this->barangay->id,
            'created_by' => $this->recorder->id,
        ]);
    }

    public function test_the_runtime_role_holds_a_column_scoped_update_grant(): void
    {
        $this->assertTrue($this->hasTablePrivilege('SELECT'));
        $this->assertTrue($this->hasTablePrivilege('INSERT'));
        $this->assertFalse($this->hasTablePrivilege('UPDATE'));
        $this->assertFalse($this->hasTablePrivilege('DELETE'));

        foreach (['is_active', 'retired_at', 'retired_reason', 'retired_by', 'updated_at'] as $column) {
            $this->assertTrue($this->hasColumnPrivilege($column, 'UPDATE'), $column);
        }

        foreach (['observed_at', 'latitude', 'longitude', 'type', 'landmark', 'note', 'barangay_id', 'created_by', 'gps_accuracy', 'address'] as $column) {
            $this->assertFalse($this->hasColumnPrivilege($column, 'UPDATE'), $column);
        }
    }

    public function test_the_recorder_retires_its_own_row_under_the_policy(): void
    {
        $this->asRuntime($this->recorder);

        try {
            $affected = DB::table('access_tags')->where('id', $this->tag->id)->update($this->retirement($this->recorder));
        } finally {
            $this->asOwner();
        }

        $stored = $this->tag->fresh();

        $this->assertSame(1, $affected);
        $this->assertFalse((bool) $stored->is_active);
        $this->assertSame('Footpath reopened.', $stored->retired_reason);
        $this->assertSame($this->recorder->id, $stored->retired_by);
    }

    public function test_an_account_that_did_not_record_the_row_changes_nothing(): void
    {
        $actors = [
            'a tanod of another barangay' => User::factory()->tanod()->forBarangay(Barangay::factory()->create())->create(),
            'a captain of the same barangay' => User::factory()->captain()->forBarangay($this->barangay)->create(),
            'a same-barangay tanod' => User::factory()->tanod()->forBarangay($this->barangay)->create(),
            'a command-center staff account' => User::factory()->staff()->create(),
            'a rescue leader' => User::factory()->rescueLeader()->create(),
        ];

        foreach ($actors as $label => $actor) {
            $this->asRuntime($actor);

            try {
                $affected = DB::table('access_tags')->where('id', $this->tag->id)->update(['retired_at' => now()]);
            } finally {
                $this->asOwner();
            }

            $this->assertSame(0, $affected, $label);
        }

        $this->assertNull($this->tag->fresh()->retired_at);
    }

    public function test_the_officer_may_retire_a_row_it_did_not_record(): void
    {
        $this->asRuntime($this->officer);

        try {
            $affected = DB::table('access_tags')->where('id', $this->tag->id)->update($this->retirement($this->officer));
        } finally {
            $this->asOwner();
        }

        $this->assertSame(1, $affected);
        $this->assertSame($this->officer->id, $this->tag->fresh()->retired_by);
    }

    public function test_the_observation_cannot_be_updated_or_the_row_deleted(): void
    {
        $this->asRuntime($this->recorder);

        try {
            $this->assertDenied(fn () => DB::table('access_tags')->where('id', $this->tag->id)->update(['observed_at' => now()->subDay()]));
            $this->assertDenied(fn () => DB::table('access_tags')->where('id', $this->tag->id)->update(['latitude' => 14.1]));
            $this->assertDenied(fn () => DB::table('access_tags')->where('id', $this->tag->id)->delete());
        } finally {
            $this->asOwner();
        }

        $this->assertSame(1, DB::table('access_tags')->where('id', $this->tag->id)->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function retirement(User $actor): array
    {
        return [
            'is_active' => false,
            'retired_at' => now(),
            'retired_reason' => 'Footpath reopened.',
            'retired_by' => $actor->id,
            'updated_at' => now(),
        ];
    }

    private function hasTablePrivilege(string $privilege): bool
    {
        return (bool) DB::scalar(
            'select has_table_privilege(?, ?, ?)',
            ['app_runtime', 'access_tags', $privilege],
        );
    }

    private function hasColumnPrivilege(string $column, string $privilege): bool
    {
        return (bool) DB::scalar(
            'select has_column_privilege(?, ?, ?, ?)',
            ['app_runtime', 'access_tags', $column, $privilege],
        );
    }

    private function assertDenied(Closure $write): void
    {
        try {
            // A savepoint keeps the test's transaction usable after the error.
            DB::transaction($write);
            $this->fail('The statement was expected to be refused by the database.');
        } catch (QueryException $exception) {
            $this->assertSame('42501', $exception->getCode(), $exception->getMessage());
        }
    }

    private function asRuntime(User $user): void
    {
        DB::statement('set local role app_runtime');
        DB::statement("select set_config('app.user_id', ?, true)", [(string) $user->getKey()]);
    }

    private function asOwner(): void
    {
        DB::statement('reset role');
        DB::statement("select set_config('app.user_id', '', true)");
    }
}
