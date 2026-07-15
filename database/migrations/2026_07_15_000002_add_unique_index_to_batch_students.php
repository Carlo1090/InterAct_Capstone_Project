<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * DB backstop for the one-row-per-(batch, student) invariant: every
     * placement path routes through EnrollmentService::enrollOrReactivate(),
     * which reconciles an existing row for the pair in place instead of
     * inserting a second one, so this index can never fire in normal flow.
     */
    public function up(): void
    {
        Schema::table('batch_students', function (Blueprint $table) {
            $table->unique(['batch_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::table('batch_students', function (Blueprint $table) {
            $table->dropUnique(['batch_id', 'student_id']);
        });
    }
};
