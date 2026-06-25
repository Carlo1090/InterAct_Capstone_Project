<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('batch_students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('batches')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('supervisor_id')->constrained('users')->cascadeOnDelete();
            $table->string('assigned_division', 150)->nullable();
            $table->enum('status', ['active', 'completed', 'dropped'])->default('active');
            $table->timestamp('enrolled_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('batch_students');
    }
};
