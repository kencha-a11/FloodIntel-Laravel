<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive columns for the adapted SITREP (D-147): the event the document
 * covers and the frozen full-document content (supplements, impact
 * provenance, adaptation note). Existing format-version-1 snapshots keep
 * null content and remain readable; the renderer falls back to their
 * stage-1 columns with the other sections marked unavailable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('situational_reports', function (Blueprint $table) {
            $table->foreignId('event_id')->nullable()->constrained('flood_events')->restrictOnDelete();
            $table->json('content')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('situational_reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('event_id');
            $table->dropColumn('content');
        });
    }
};
