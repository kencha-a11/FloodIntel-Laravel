<?php

use App\Support\RlsScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issued situational-report snapshots and their included-report provenance
 * (D-40, FR-81).
 *
 * The included rows restrict on delete in both directions: an issued document
 * cannot lose the reports that produced its figures, and a snapshot cannot be
 * deleted while its provenance exists. app_runtime receives SELECT and INSERT
 * only, so "never edited, never deleted" is a database rule as well as a model
 * guard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('situational_reports', function (Blueprint $table) {
            $table->id();
            $table->timestamp('window_starts_at');
            $table->timestamp('window_ends_at');
            $table->timestamp('cutoff_at');
            $table->string('cutoff_label');
            $table->string('timezone');
            $table->timestamp('snapshot_at');
            $table->unsignedInteger('revision');
            $table->foreignId('predecessor_id')->nullable()->constrained('situational_reports')->restrictOnDelete();
            $table->string('format_version');
            $table->foreignId('generated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('generated_by_name');
            $table->string('generated_by_role');
            $table->json('status_counts');
            $table->json('totals');
            $table->json('barangays');
            $table->json('latency');
            $table->json('stage_two');
            $table->timestamps();

            // One revision number per cutoff; the unique index is also the
            // lookup the previous-revision path uses.
            $table->unique(['cutoff_at', 'revision']);
        });

        Schema::create('situational_report_inclusions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('situational_report_id')->constrained()->restrictOnDelete();
            $table->foreignId('flood_report_id')->constrained()->restrictOnDelete();
            $table->foreignId('barangay_id')->constrained()->restrictOnDelete();
            $table->string('alert_level')->nullable();
            $table->string('flood_level')->nullable();
            $table->timestamp('observed_at');
            $table->timestamp('validated_at');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['situational_report_id', 'flood_report_id']);
        });

        $connection = Schema::getConnection();

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $connection->unprepared(<<<'SQL'
ALTER TABLE situational_reports ENABLE ROW LEVEL SECURITY;
ALTER TABLE situational_report_inclusions ENABLE ROW LEVEL SECURITY;

-- Only the two command-center roles hold ConsolidateReports or
-- GenerateProvincialReport, and a recipient without either cannot open a
-- revision (D-42, FR-86).
CREATE POLICY situational_reports_select ON situational_reports FOR SELECT TO app_runtime
USING (app_user_role() IN ('mdrrmo_officer', 'mdrrmo_staff'));

CREATE POLICY situational_reports_insert ON situational_reports FOR INSERT TO app_runtime
WITH CHECK (
  app_user_role() IN ('mdrrmo_officer', 'mdrrmo_staff')
  AND generated_by_user_id = app_user_id()
);

CREATE POLICY situational_report_inclusions_select ON situational_report_inclusions FOR SELECT TO app_runtime
USING (EXISTS (
  SELECT 1 FROM situational_reports r
  WHERE r.id = situational_report_inclusions.situational_report_id
));

CREATE POLICY situational_report_inclusions_insert ON situational_report_inclusions FOR INSERT TO app_runtime
WITH CHECK (EXISTS (
  SELECT 1 FROM situational_reports r
  WHERE r.id = situational_report_inclusions.situational_report_id
    AND r.generated_by_user_id = app_user_id()
));

GRANT SELECT, INSERT ON TABLE situational_reports TO app_runtime;
GRANT USAGE, SELECT ON SEQUENCE situational_reports_id_seq TO app_runtime;
GRANT SELECT, INSERT ON TABLE situational_report_inclusions TO app_runtime;
GRANT USAGE, SELECT ON SEQUENCE situational_report_inclusions_id_seq TO app_runtime;
SQL);

        RlsScope::assertEveryTableDecided($connection);
    }

    public function down(): void
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'pgsql') {
            $connection->unprepared(<<<'SQL'
DROP POLICY IF EXISTS situational_report_inclusions_insert ON situational_report_inclusions;
DROP POLICY IF EXISTS situational_report_inclusions_select ON situational_report_inclusions;
DROP POLICY IF EXISTS situational_reports_insert ON situational_reports;
DROP POLICY IF EXISTS situational_reports_select ON situational_reports;
REVOKE ALL ON TABLE situational_report_inclusions FROM app_runtime;
REVOKE ALL ON TABLE situational_reports FROM app_runtime;
SQL);
        }

        Schema::dropIfExists('situational_report_inclusions');
        Schema::dropIfExists('situational_reports');
    }
};
