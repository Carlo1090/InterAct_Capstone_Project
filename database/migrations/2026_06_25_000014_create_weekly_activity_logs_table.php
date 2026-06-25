<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weekly_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('batch_id')->constrained('batches')->cascadeOnDelete();
            $table->foreignId('weekly_log_id')->nullable()->constrained('weekly_logs')->nullOnDelete();
            $table->date('week_start');
            $table->date('week_end');
            $table->string('area_assigned', 150)->nullable();
            $table->decimal('no_of_hours', 5, 1)->nullable();
            $table->enum('status', ['draft', 'submitted', 'approved'])->default('draft');
            $table->timestamp('submitted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weekly_activity_logs');
    }
};
