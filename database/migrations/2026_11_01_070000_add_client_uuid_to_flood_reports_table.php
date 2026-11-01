<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FR-18's server-side idempotency key (D-110). The column is opaque and
 * nullable rather than a UUID type: the client's offline fallback has always
 * minted `local-<time>-<hex>` ids, and a strict format would reject every
 * report queued by a browser without `crypto.randomUUID`. The unique index is
 * scoped to the reporter, because two reporters may mint the same id, and
 * rows without a key stay unrestricted on both SQLite and Postgres.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flood_reports', function (Blueprint $table) {
            $table->string('client_uuid', 64)->nullable();

            $table->unique(['user_id', 'client_uuid']);
        });
    }

    public function down(): void
    {
        Schema::table('flood_reports', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'client_uuid']);
            $table->dropColumn('client_uuid');
        });
    }
};
