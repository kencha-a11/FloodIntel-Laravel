<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('realtime_latency_samples', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('report_id')->nullable()->constrained('flood_reports')->nullOnDelete();
            $table->string('event', 40);
            $table->timestampTz('received_at')->nullable();
            $table->timestampTz('applied_at')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
        });

        // Disable RLS on this table since it's in the RlsScope UNPROTECTED list
        // (framework telemetry with no tenant fields beyond report_id)
        $connection = Schema::getConnection();
        $connection->unprepared('ALTER TABLE realtime_latency_samples DISABLE ROW LEVEL SECURITY');
    }

    public function down(): void
    {
        $connection = Schema::getConnection();
        $connection->unprepared('ALTER TABLE realtime_latency_samples ENABLE ROW LEVEL SECURITY');
        Schema::dropIfExists('realtime_latency_samples');
    }
};
