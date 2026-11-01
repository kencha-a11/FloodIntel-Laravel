<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flood_impact_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('barangay_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained('flood_events')->cascadeOnDelete();
            $table->unsignedInteger('families');
            $table->unsignedInteger('individuals');
            $table->json('breakdown')->nullable();
            $table->json('related_level_report_ids')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamp('reported_at');
            $table->string('status')->default('submitted');
            $table->foreignId('supersedes_id')->nullable()->constrained('flood_impact_reports')->nullOnDelete();
            $table->timestamps();

            $table->index(['barangay_id', 'event_id']);
            $table->unique('supersedes_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flood_impact_reports');
    }
};
