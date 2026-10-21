<?php

use App\Enums\FloodReportStatus;
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
        Schema::create('flood_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('barangay_id')->constrained()->restrictOnDelete();
            $table->string('flood_level');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->decimal('gps_accuracy', 8, 2)->nullable();
            $table->string('photo_path');
            $table->text('notes')->nullable();
            $table->timestamp('reported_at')->nullable();
            $table->string('status')->default(FloodReportStatus::Submitted->value)->index();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->index(['barangay_id', 'reported_at']);
            $table->index(['user_id', 'reported_at']);
            $table->index('flood_level');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flood_reports');
    }
};
