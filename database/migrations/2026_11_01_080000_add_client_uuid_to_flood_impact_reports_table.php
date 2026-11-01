<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FR-18's idempotency key for impact reports (D-110). The shape mirrors
 * `flood_reports.client_uuid` deliberately: an opaque nullable string rather
 * than a UUID type, scoped by a composite unique index to the reporter,
 * because the offline client's fallback id is not a UUID and two reporters
 * may mint the same value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flood_impact_reports', function (Blueprint $table) {
            $table->string('client_uuid', 64)->nullable();

            $table->unique(['user_id', 'client_uuid']);
        });
    }

    public function down(): void
    {
        Schema::table('flood_impact_reports', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'client_uuid']);
            $table->dropColumn('client_uuid');
        });
    }
};
