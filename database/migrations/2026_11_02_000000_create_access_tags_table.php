<?php

use App\Support\RlsScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Access tags: a constraint on a place, recorded by a field account (FR-113,
 * FR-114, D-125).
 *
 * Three rules are enforced here rather than in Eloquent. The insert policy
 * admits only the two field roles and forces the row's barangay and recorder
 * to the authenticated account (FR-115, FR-118). There is no UPDATE policy and
 * no DELETE grant, so a tag cannot be rewritten and cannot be removed —
 * retirement is the only lifecycle change and it lands with `A11Y-05`
 * (FR-121). The table has no resident, household, or medical column: the
 * subject is a place (D-124).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('barangay_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('type')->index();
            $table->string('landmark')->nullable();
            $table->text('note')->nullable();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->decimal('gps_accuracy', 8, 2)->nullable();
            $table->string('address')->nullable();
            $table->timestamp('observed_at');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['barangay_id', 'is_active']);
        });

        $connection = Schema::getConnection();

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $connection->unprepared(<<<'SQL'
ALTER TABLE access_tags ENABLE ROW LEVEL SECURITY;

-- FR-119 mirrors FR-106: the command center reads every barangay, a
-- barangay-scoped field account reads its own, and anyone else reads only
-- the tags it recorded.
CREATE POLICY access_tags_select ON access_tags FOR SELECT TO app_runtime
USING (
  app_user_role() IN ('mdrrmo_officer', 'mdrrmo_staff')
  OR (app_user_role() IN ('barangay_captain', 'barangay_tanod')
      AND barangay_id = app_user_barangay())
  OR created_by = app_user_id()
);

CREATE POLICY access_tags_insert ON access_tags FOR INSERT TO app_runtime
WITH CHECK (
  app_user_role() IN ('barangay_captain', 'barangay_tanod')
  AND created_by = app_user_id()
  AND barangay_id = app_user_barangay()
);

-- No UPDATE and no DELETE: FR-121 retires rather than deletes, and the
-- retirement path is granted with the route that performs it (A11Y-05).
GRANT SELECT, INSERT ON TABLE access_tags TO app_runtime;
GRANT USAGE, SELECT ON SEQUENCE access_tags_id_seq TO app_runtime;
SQL);

        RlsScope::assertEveryTableDecided($connection);
    }

    public function down(): void
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'pgsql') {
            $connection->unprepared(<<<'SQL'
DROP POLICY IF EXISTS access_tags_insert ON access_tags;
DROP POLICY IF EXISTS access_tags_select ON access_tags;
REVOKE ALL ON TABLE access_tags FROM app_runtime;
SQL);
        }

        Schema::dropIfExists('access_tags');
    }
};
