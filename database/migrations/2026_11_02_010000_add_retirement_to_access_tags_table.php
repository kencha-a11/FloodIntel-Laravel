<?php

use App\Support\RlsScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retirement: the only lifecycle change a tag has (FR-121, D-131).
 *
 * The columns record the instant, the optional reason, and the account that
 * retired the tag. The policy admits the recording account or a `ManageUsers`
 * holder, and `mdrrmo_officer` is the only role that holds that ability
 * (FR-118, D-128). The grant is column-scoped, so a raw statement under
 * `app_runtime` can change the retirement columns and nothing else — the
 * recorded observation stays immutable (FR-114). There is still no DELETE
 * grant: a tag is retired, never removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('access_tags', function (Blueprint $table) {
            $table->timestamp('retired_at')->nullable();
            $table->string('retired_reason')->nullable();
            $table->foreignId('retired_by')->nullable()->constrained('users')->restrictOnDelete();
        });

        $connection = Schema::getConnection();

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $connection->unprepared(<<<'SQL'
CREATE POLICY access_tags_update ON access_tags FOR UPDATE TO app_runtime
USING (
  created_by = app_user_id()
  OR app_user_role() = 'mdrrmo_officer'
)
WITH CHECK (
  created_by = app_user_id()
  OR app_user_role() = 'mdrrmo_officer'
);

GRANT UPDATE (is_active, retired_at, retired_reason, retired_by, updated_at)
  ON TABLE access_tags TO app_runtime;
SQL);

        RlsScope::assertEveryTableDecided($connection);
    }

    public function down(): void
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'pgsql') {
            $connection->unprepared(<<<'SQL'
REVOKE UPDATE (is_active, retired_at, retired_reason, retired_by, updated_at)
  ON TABLE access_tags FROM app_runtime;
DROP POLICY IF EXISTS access_tags_update ON access_tags;
SQL);
        }

        Schema::table('access_tags', function (Blueprint $table) {
            $table->dropConstrainedForeignId('retired_by');
            $table->dropColumn(['retired_at', 'retired_reason']);
        });
    }
};
