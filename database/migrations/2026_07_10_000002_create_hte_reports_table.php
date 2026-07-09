<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hte_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coordinator_id')->constrained('users')->cascadeOnDelete();
            // HTE reports are academic-year scoped and combine every in-scope
            // program by default (program_id null). program_id is retained,
            // nullable, so a single-program filtered report can also be saved.
            $table->foreignId('program_id')->nullable()->constrained('programs')->nullOnDelete();
            $table->string('academic_year', 20);
            // Curated report state: candidate-row overrides, manual rows,
            // tombstones, and signatories. Mirrors sipp_annual_reports.report_data.
            $table->json('report_data');
            $table->enum('status', ['draft', 'finalized'])->default('draft');
            $table->timestamp('generated_at')->useCurrent();

            $table->unique(['coordinator_id', 'program_id', 'academic_year'], 'hte_reports_scope_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hte_reports');
    }
};
