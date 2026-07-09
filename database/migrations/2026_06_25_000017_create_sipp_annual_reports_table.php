<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sipp_annual_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coordinator_id')->constrained('users')->cascadeOnDelete();
            // Annual SIPP reports are program+AY scoped. batch_id is retained but
            // nullable for backward compatibility; program_id is the real scope.
            $table->foreignId('program_id')->nullable()->constrained('programs')->nullOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('batches')->cascadeOnDelete();
            $table->string('academic_year', 20);
            $table->json('report_data'); // Structured SIPP report fields as required by CHED/TESDA
            $table->enum('status', ['draft', 'finalized'])->default('draft');
            $table->timestamp('generated_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sipp_annual_reports');
    }
};
