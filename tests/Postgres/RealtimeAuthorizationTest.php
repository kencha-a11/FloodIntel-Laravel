<?php

namespace Tests\Postgres;

use App\Models\Barangay;
use App\Models\User;
use App\Support\RealtimeScope;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * FR-68, D-65: a private channel is readable only by the subscriber the
 * server-derived scope allows, and a cross-barangay payload is not delivered.
 *
 * Supabase owns the auth and realtime schemas, so this test emulates the two
 * things the policy reads: auth.jwt() (the request's claims) and
 * realtime.messages (the broadcast feed RLS filters).
 */
final class RealtimeAuthorizationTest extends PostgresTestCase
{
    use LazilyRefreshDatabase;

    private Barangay $barangayA;

    private Barangay $barangayB;

    private User $officer;

    private User $staff;

    private User $captainA;

    private User $tanodB;

    private User $rescueLeader;

    private User $unverifiedA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->emulateSupabase();

        $this->barangayA = Barangay::factory()->create(['name' => 'San Juan', 'code' => 'SJ']);
        $this->barangayB = Barangay::factory()->create(['name' => 'San Pedro', 'code' => 'SP']);

        $this->officer = User::factory()->officer()->create();
        $this->staff = User::factory()->staff()->create();
        $this->captainA = User::factory()->captain()->forBarangay($this->barangayA)->create();
        $this->tanodB = User::factory()->tanod()->forBarangay($this->barangayB)->create();
        $this->rescueLeader = User::factory()->rescueLeader()->create();
        $this->unverifiedA = User::factory()->tanod()->forBarangay($this->barangayA)->unverified()->create();

        $this->seedBroadcasts();
    }

    public function test_the_function_grants_the_command_center_only_the_all_topic(): void
    {
        foreach ([$this->officer, $this->staff] as $user) {
            $this->assertTrue($this->canReceive($user, 'reports:all'));
            $this->assertFalse($this->canReceive($user, $this->barangayTopic($this->barangayA)));
            $this->assertFalse($this->canReceive($user, $this->userTopic($this->tanodB)));
        }

        $this->assertFalse($this->canReceive($this->rescueLeader, 'reports:all'));
        $this->assertTrue($this->canReceive($this->rescueLeader, $this->userTopic($this->rescueLeader)));
    }

    public function test_the_function_grants_a_barangay_user_only_their_barangay_and_their_own_topic(): void
    {
        foreach ([$this->captainA, $this->tanodB] as $user) {
            $own = $user === $this->captainA ? $this->barangayA : $this->barangayB;
            $other = $user === $this->captainA ? $this->barangayB : $this->barangayA;

            $this->assertTrue($this->canReceive($user, $this->barangayTopic($own)));
            $this->assertTrue($this->canReceive($user, $this->userTopic($user)));
            $this->assertFalse($this->canReceive($user, $this->barangayTopic($other)));
            $this->assertFalse($this->canReceive($user, $this->userTopic($this->officer)));
            $this->assertFalse($this->canReceive($user, 'reports:all'));
        }
    }

    public function test_the_function_denies_an_unverified_user_and_an_unknown_id(): void
    {
        foreach ([
            'reports:all',
            $this->barangayTopic($this->barangayA),
            $this->userTopic($this->unverifiedA),
        ] as $topic) {
            $this->assertFalse($this->canReceive($this->unverifiedA, $topic));
        }

        $this->assertFalse($this->canReceive(null, 'reports:all'));
    }

    public function test_a_private_broadcast_reaches_only_the_authorized_topics(): void
    {
        $this->asSubscriber($this->tanodB);

        try {
            $this->assertSame(
                [
                    $this->barangayTopic($this->barangayB),
                    $this->userTopic($this->tanodB),
                ],
                $this->visibleBroadcastTopics(),
                'A Barangay B subscriber must see their barangay and their own topic, and no other.'
            );
        } finally {
            $this->resetSubscriber();
        }

        $this->asSubscriber($this->officer);

        try {
            $this->assertSame(['reports:all'], $this->visibleBroadcastTopics());
        } finally {
            $this->resetSubscriber();
        }

        $this->asSubscriber($this->captainA);

        try {
            $this->assertSame(
                [
                    $this->barangayTopic($this->barangayA),
                    $this->userTopic($this->captainA),
                ],
                $this->visibleBroadcastTopics()
            );
        } finally {
            $this->resetSubscriber();
        }
    }

    public function test_a_cross_barangay_payload_is_not_visible_to_a_pinned_subscriber(): void
    {
        $this->asSubscriber($this->tanodB);

        try {
            $this->assertSame(0, DB::table('realtime.messages')
                ->where('topic', $this->barangayTopic($this->barangayA))
                ->count(), 'A Barangay B subscriber must not receive a Barangay A payload.');
        } finally {
            $this->resetSubscriber();
        }
    }

    public function test_an_unauthenticated_request_reads_nothing(): void
    {
        $this->asAnonymousSubscriber();

        try {
            $this->assertSame([], $this->visibleBroadcastTopics());
        } finally {
            $this->resetSubscriber();
        }
    }

    public function test_a_presence_or_system_message_never_passes_the_policy(): void
    {
        DB::table('realtime.messages')->insert([
            'topic' => $this->userTopic($this->tanodB),
            'extension' => 'presence',
            'payload' => json_encode(['event' => 'sync']),
        ]);

        $this->asSubscriber($this->tanodB);

        try {
            $this->assertSame(
                [$this->barangayTopic($this->barangayB), $this->userTopic($this->tanodB)],
                $this->visibleBroadcastTopics()
            );
        } finally {
            $this->resetSubscriber();
        }
    }

    public function test_the_policy_is_the_scope_function_and_only_authenticated_may_read(): void
    {
        $policy = DB::table('pg_policies')
            ->where('schemaname', 'realtime')
            ->where('tablename', 'messages')
            ->where('policyname', 'app_realtime_receive')
            ->first();

        $this->assertNotNull($policy, 'The migration must install app_realtime_receive on realtime.messages.');
        $this->assertStringContainsString('app_realtime_can_receive', (string) $policy->qual);
        $this->assertStringContainsString('auth.jwt()', (string) $policy->qual);
        $this->assertStringContainsString('broadcast', (string) $policy->qual);
        $this->assertSame('{authenticated}', (string) $policy->roles);

        $this->assertTrue((bool) DB::scalar(
            "select has_function_privilege('app_runtime', 'app_realtime_can_receive(bigint, text)', 'execute')"
        ));
        $this->assertTrue((bool) DB::scalar(
            "select has_function_privilege('authenticated', 'app_realtime_can_receive(bigint, text)', 'execute')"
        ));
        $this->assertFalse((bool) DB::scalar(<<<'SQL'
select exists (
  select 1
  from pg_proc p
  join pg_namespace n on n.oid = p.pronamespace
  cross join lateral aclexplode(p.proacl) a
  where n.nspname = 'public'
    and p.proname = 'app_realtime_can_receive'
    and a.grantee = 0
)
SQL));
    }

    /**
     * Creates the two schemas Supabase owns. The application migration ran
     * before this, when realtime.messages did not exist yet, so install() is
     * called again once the emulation is in place.
     */
    private function emulateSupabase(): void
    {
        DB::unprepared(<<<'SQL'
DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'authenticated') THEN
    CREATE ROLE authenticated NOLOGIN;
  END IF;
END
$$;

CREATE SCHEMA IF NOT EXISTS auth;
CREATE SCHEMA IF NOT EXISTS realtime;

CREATE OR REPLACE FUNCTION auth.jwt() RETURNS jsonb
LANGUAGE sql STABLE AS $$
  SELECT coalesce(
    nullif(current_setting('request.jwt.claim', true), ''),
    nullif(current_setting('request.jwt.claims', true), '')
  )::jsonb
$$;

CREATE TABLE IF NOT EXISTS realtime.messages (
  id bigserial primary key,
  topic text not null,
  extension text not null default 'broadcast',
  payload jsonb,
  inserted_at timestamptz not null default now()
);

GRANT authenticated TO current_user;
GRANT USAGE ON SCHEMA auth TO authenticated;
GRANT EXECUTE ON FUNCTION auth.jwt() TO authenticated;
GRANT USAGE ON SCHEMA realtime TO authenticated;
GRANT SELECT ON realtime.messages TO authenticated;
ALTER TABLE realtime.messages ENABLE ROW LEVEL SECURITY;
SQL);

        RealtimeScope::install(DB::connection());
    }

    private function seedBroadcasts(): void
    {
        foreach ([
            'reports:all',
            $this->barangayTopic($this->barangayA),
            $this->barangayTopic($this->barangayB),
            $this->userTopic($this->captainA),
            $this->userTopic($this->tanodB),
        ] as $index => $topic) {
            DB::table('realtime.messages')->insert([
                'topic' => $topic,
                'extension' => 'broadcast',
                'payload' => json_encode(['id' => $index + 1]),
            ]);
        }
    }

    private function asSubscriber(User $user): void
    {
        DB::statement("select set_config('request.jwt.claims', ?, true)", [
            json_encode(['sub' => (string) $user->getKey(), 'role' => 'authenticated']),
        ]);
        DB::statement('set local role authenticated');
    }

    private function asAnonymousSubscriber(): void
    {
        DB::statement("select set_config('request.jwt.claims', '', true)");
        DB::statement('set local role authenticated');
    }

    private function resetSubscriber(): void
    {
        DB::statement('reset role');
        DB::statement("select set_config('request.jwt.claims', '', true)");
    }

    /**
     * @return list<string>
     */
    private function visibleBroadcastTopics(): array
    {
        return DB::table('realtime.messages')
            ->select('topic')
            ->distinct()
            ->orderBy('topic')
            ->pluck('topic')
            ->all();
    }

    private function canReceive(?User $user, string $topic): bool
    {
        return (bool) DB::scalar(
            'select app_realtime_can_receive(?, ?)',
            [$user?->getKey(), $topic]
        );
    }

    private function barangayTopic(Barangay $barangay): string
    {
        return 'reports:barangay:'.$barangay->id;
    }

    private function userTopic(User $user): string
    {
        return 'reports:user:'.$user->id;
    }
}
