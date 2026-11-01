<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FR-14's correction link. The superseded report keeps its status (D-21);
 * the link is the record, so it is nullable, unique, and restricted: the
 * prior report cannot be removed while a correction points at it (FR-30).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flood_reports', function (Blueprint $table) {
            $table->foreignId('supersedes_id')
                ->nullable()
                ->constrained('flood_reports')
                ->restrictOnDelete();

            $table->unique('supersedes_id');
        });
    }

    public function down(): void
    {
        Schema::table('flood_reports', function (Blueprint $table) {
            $table->dropUnique(['supersedes_id']);
            $table->dropConstrainedForeignId('supersedes_id');
        });
    }
};
