<?php

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
        Schema::table('flood_reports', function (Blueprint $table): void {
            $table->string('alert_level')->nullable()->index();
            $table->string('flood_type')->nullable()->index();
            $table->unsignedSmallInteger('depth_cm')->nullable();
            $table->string('landmark', 255)->nullable();
            $table->string('flood_level')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $nullCount = \App\Models\FloodReport::whereNotNull('alert_level')
            ->orWhereNotNull('flood_type')
            ->orWhereNotNull('depth_cm')
            ->orWhereNotNull('landmark')
            ->count();

        if ($nullCount > 0) {
            throw new \LogicException(
                'Cannot roll back: ' . $nullCount .
                ' report(s) have alert fields populated. Remove or clear them before re-tightening flood_level.'
            );
        }

        Schema::table('flood_reports', function (Blueprint $table): void {
            $table->dropColumn(['alert_level', 'flood_type', 'depth_cm', 'landmark']);
            $table->string('flood_level')->nullable(false)->change();
        });
    }
};