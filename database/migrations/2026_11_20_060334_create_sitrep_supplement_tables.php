<?php

use App\Support\RlsScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SITREP completion (D-147): reviewed supplement drafts, append-only issuance
 * records, and append-only scheduled-run attempts.
 *
 * Supplements are mutable command-center drafts; once a snapshot is prepared
 * their content is frozen into situational_reports.content and the draft rows
 * no longer affect any issued document. Issuances and runs are append-only:
 * app_runtime holds SELECT/INSERT only, so a recorded issuance or run outcome
 * can never be rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('situational_report_supplements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('flood_events')->restrictOnDelete();
            $table->string('section', 40);
            $table->foreignId('barangay_id')->nullable()->constrained('barangays')->restrictOnDelete();
            $table->json('payload');
            $table->string('source', 500)->nullable();
            $table->timestamp('observed_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name');
            $table->timestamps();

            $table->index(['event_id', 'section']);
        });

        Schema::create('situational_report_issuances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('situational_report_id')->unique()->constrained('situational_reports')->restrictOnDelete();
            $table->foreignId('event_id')->constrained('flood_events')->restrictOnDelete();
            $table->unsignedInteger('report_number');
            $table->foreignId('issued_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('issued_by_name');
            $table->string('issued_by_role');
            $table->json('office');
            $table->timestamp('issued_at');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['event_id', 'report_number']);
        });

        Schema::create('situational_report_runs', function (Blueprint $table) {
            $table->id();
            $table->timestamp('cutoff_at');
            $table->timestamp('window_starts_at');
            $table->string('state', 20);
            $table->string('reason', 1000)->nullable();
            $table->foreignId('situational_report_id')->nullable()->constrained('situational_reports')->restrictOnDelete();
            $table->string('triggered_by', 20);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('cutoff_at');
        });

        $connection = Schema::getConnection();

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $connection->unprepared(<<<'SQL'
ALTER TABLE situational_report_supplements ENABLE ROW LEVEL SECURITY;
ALTER TABLE situational_report_issuances ENABLE ROW LEVEL SECURITY;
ALTER TABLE situational_report_runs ENABLE ROW LEVEL SECURITY;

-- Supplements are command-center work product (D-42): the two command-center
-- roles read and write drafts; field roles never see them.
CREATE POLICY situational_report_supplements_all ON situational_report_supplements FOR ALL TO app_runtime
USING (app_user_role() IN ('mdrrmo_officer', 'mdrrmo_staff'))
WITH CHECK (app_user_role() IN ('mdrrmo_officer', 'mdrrmo_staff'));

-- Issuance is the officer's act (D-42); the record names that officer.
CREATE POLICY situational_report_issuances_select ON situational_report_issuances FOR SELECT TO app_runtime
USING (app_user_role() IN ('mdrrmo_officer', 'mdrrmo_staff'));

CREATE POLICY situational_report_issuances_insert ON situational_report_issuances FOR INSERT TO app_runtime
WITH CHECK (
  app_user_role() = 'mdrrmo_officer'
  AND issued_by_user_id = app_user_id()
);

-- Run outcomes are visible to the command center; a scheduled attempt may
-- carry no actor when no scheduler principal is configured (the failure is
-- still recorded, D-44).
CREATE POLICY situational_report_runs_select ON situational_report_runs FOR SELECT TO app_runtime
USING (app_user_role() IN ('mdrrmo_officer', 'mdrrmo_staff'));

CREATE POLICY situational_report_runs_insert ON situational_report_runs FOR INSERT TO app_runtime
WITH CHECK (
  app_user_role() IN ('mdrrmo_officer', 'mdrrmo_staff')
  AND (actor_user_id IS NULL OR actor_user_id = app_user_id())
);

GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE situational_report_supplements TO app_runtime;
GRANT USAGE, SELECT ON SEQUENCE situational_report_supplements_id_seq TO app_runtime;
GRANT SELECT, INSERT ON TABLE situational_report_issuances TO app_runtime;
GRANT USAGE, SELECT ON SEQUENCE situational_report_issuances_id_seq TO app_runtime;
GRANT SELECT, INSERT ON TABLE situational_report_runs TO app_runtime;
GRANT USAGE, SELECT ON SEQUENCE situational_report_runs_id_seq TO app_runtime;
SQL);

        RlsScope::assertEveryTableDecided($connection);
    }

    public function down(): void
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'pgsql') {
            $connection->unprepared(<<<'SQL'
DROP POLICY IF EXISTS situational_report_supplements_all ON situational_report_supplements;
DROP POLICY IF EXISTS situational_report_issuances_select ON situational_report_issuances;
DROP POLICY IF EXISTS situational_report_issuances_insert ON situational_report_issuances;
DROP POLICY IF EXISTS situational_report_runs_select ON situational_report_runs;
DROP POLICY IF EXISTS situational_report_runs_insert ON situational_report_runs;
REVOKE ALL ON TABLE situational_report_supplements FROM app_runtime;
REVOKE ALL ON TABLE situational_report_issuances FROM app_runtime;
REVOKE ALL ON TABLE situational_report_runs FROM app_runtime;
SQL);
        }

        Schema::dropIfExists('situational_report_runs');
        Schema::dropIfExists('situational_report_issuances');
        Schema::dropIfExists('situational_report_supplements');
    }
};
