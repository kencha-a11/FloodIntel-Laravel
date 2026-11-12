<?php

use App\Support\RlsScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('login_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('personal_access_token_id')->nullable()->constrained('personal_access_tokens')->nullOnDelete();
            $table->string('outcome', 16);
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
        });

        $connection = Schema::getConnection();

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        // The runtime role writes the row during login, before an identity
        // exists, and reads it back for the session list (AUTH-17). The table
        // stays append-only: select and insert only, no update or delete.
        $connection->unprepared(<<<'SQL'
ALTER TABLE login_events ENABLE ROW LEVEL SECURITY;

CREATE POLICY login_events_select ON login_events FOR SELECT TO app_runtime
USING (user_id = app_user_id());

-- The USING clause is true so the policy applies to all inserts
CREATE POLICY login_events_insert ON login_events FOR INSERT TO app_runtime
USING (true)
WITH CHECK (true);

GRANT SELECT, INSERT ON TABLE login_events TO app_runtime;
GRANT USAGE, SELECT ON SEQUENCE login_events_id_seq TO app_runtime;
SQL);

        RlsScope::assertEveryTableDecided($connection);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'pgsql') {
            $connection->unprepared(<<<'SQL'
DROP POLICY IF EXISTS login_events_insert ON login_events;
DROP POLICY IF EXISTS login_events_select ON login_events;
ALTER TABLE login_events DISABLE ROW LEVEL SECURITY;
REVOKE ALL ON TABLE login_events FROM app_runtime;
SQL);
        }

        Schema::dropIfExists('login_events');
    }
};
