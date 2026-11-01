<?php

use App\Support\RealtimeScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * The private-channel authorization (FR-68, D-65).
 *
 * The function is created everywhere; the realtime.messages policy is created
 * on Supabase, where that table and auth.jwt() exist. Local Postgres has
 * neither, so the test emulates them and installs the same policy.
 */
return new class extends Migration
{
    public function up(): void
    {
        RealtimeScope::install(Schema::getConnection());
    }

    public function down(): void
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $connection->unprepared(<<<'SQL'
DO $$
BEGIN
  IF to_regclass('realtime.messages') IS NOT NULL THEN
    DROP POLICY IF EXISTS app_realtime_receive ON realtime.messages;
  END IF;
END
$$;

DROP FUNCTION IF EXISTS app_realtime_can_receive(bigint, text);
SQL);
    }
};
