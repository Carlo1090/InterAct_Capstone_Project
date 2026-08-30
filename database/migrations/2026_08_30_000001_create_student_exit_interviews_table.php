<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_exit_interviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('batch_id')->constrained('batches')->cascadeOnDelete();

            // Section A — only the fields the paper form asks the student to
            // WRITE. Name, program, company, training period and the
            // coordinator are re-derived from the enrollment at render time,
            // exactly as the individual info sheet re-derives its read-only
            // header, so a stale copy can never print.
            $table->json('student_info');

            // Sections B-G — the fourteen answers plus the four Yes/No choices,
            // keyed by question number. A JSON column for the same reason
            // student_information_sheets uses them: the question set belongs to
            // the paper form, not to the schema, and adding a question must not
            // mean a migration.
            $table->json('responses');

            // "SECTION FOR OJT/INTERNSHIP COORDINATOR" — compliance
            // verification, the pending-requirements detail, and remarks.
            // Nullable because it is filled after the student submits, by
            // somebody else.
            $table->json('coordinator_section')->nullable();

            $table->enum('submission_status', ['draft', 'submitted', 'reviewed'])->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();

            // One exit interview per placement — the form is filled once, at
            // the end of it. Mirrors batch_students' own UNIQUE(batch, student)
            // backstop rather than relying on the controller alone.
            $table->unique(['student_id', 'batch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_exit_interviews');
    }
};
