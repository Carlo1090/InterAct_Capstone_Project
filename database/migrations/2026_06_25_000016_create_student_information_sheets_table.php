<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_information_sheets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('batch_id')->constrained('batches')->cascadeOnDelete();
            $table->json('personal_info');   // Section I — name, DOB, sex, address, contact, email
            $table->json('academic_info');   // Section II — program, year level, department, coordinator
            $table->json('ojt_info');        // Section III — company, address, supervisor, division, dates
            $table->json('emergency_contact')->nullable();
            $table->enum('submission_status', ['draft', 'submitted', 'approved'])->default('draft');
            $table->timestamp('submitted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_information_sheets');
    }
};
