<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_info_sheets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coordinator_id')->constrained('users')->cascadeOnDelete();
            // The GROUP Student Information Sheet is one document per company:
            // every in-scope intern placed there is rostered above a single
            // coordinator-typed Internship Company Information block.
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('academic_year', 20);
            // Curated sheet state: the editable department header line, the
            // coordinator-typed company block, plus candidate-row overrides,
            // manual rows and tombstones. Mirrors hte_reports.report_data.
            $table->json('sheet_data');
            $table->enum('status', ['draft', 'finalized'])->default('draft');
            $table->timestamp('generated_at')->useCurrent();

            $table->unique(['coordinator_id', 'company_id', 'academic_year'], 'group_info_sheets_scope_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_info_sheets');
    }
};
