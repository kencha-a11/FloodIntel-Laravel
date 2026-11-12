<?php

use App\Support\RlsScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The immutable status audit (D-22, NFR-05).
 *
 * The RLS half is in this migration because app_runtime has no default
 * privileges: without the grants the runtime cannot write the log, and
 * without the scope entry the guard refuses the migration. There is no
 * UPDATE or DELETE policy and no such grant, so append-only holds in the
 * database, not only in Eloquent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flood_report_status_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flood_report_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name');
            $table->string('actor_role');
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['flood_report_id', 'created_at']);
        });

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $connection->unprepared(<<<'SQL'
ALTER TABLE flood_report_status_changes ENABLE ROW LEVEL SECURITY;

-- The referenced report is itself RLS-filtered for this role, so the EXISTS
-- admits only audit rows whose report the caller can see.
CREATE POLICY flood_report_status_changes_select ON flood_report_status_changes FOR SELECT TO app_runtime
USING (EXISTS (
  SELECT 1 FROM flood_reports r
  WHERE r.id = flood_report_status_changes.flood_report_id
));

CREATE POLICY flood_report_status_changes_insert ON flood_report_status_changes FOR INSERT TO app_runtime
WITH CHECK (user_id = app_user_id());

GRANT SELECT, INSERT ON TABLE flood_report_status_changes TO app_runtime;
GRANT USAGE, SELECT ON SEQUENCE flood_report_status_changes_id_seq TO app_runtime;
SQL);

        RlsScope::assertEveryTableDecided($connection);
    }

    public function down(): void
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'pgsql') {
            $connection->unprepared(<<<'SQL'
DROP POLICY IF EXISTS flood_report_status_changes_select ON flood_report_status_changes;
DROP POLICY IF EXISTS flood_report_status_changes_insert ON flood_report_status_changes;
REVOKE ALL ON TABLE flood_report_status_changes FROM app_runtime;
SQL);
        }

        Schema::dropIfExists('flood_report_status_changes');
    }
};
