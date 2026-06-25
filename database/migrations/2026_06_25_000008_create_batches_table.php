<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained('programs')->cascadeOnDelete();
            $table->foreignId('coordinator_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('journal_template_id')->nullable()->constrained('journal_templates')->nullOnDelete();
            $table->string('name', 150);
            $table->string('academic_year', 20);
            $table->string('semester', 30);
            $table->date('start_date');
            $table->date('end_date');
            $table->integer('required_hours');
            $table->tinyInteger('working_days_per_week');
            $table->time('daily_reminder_time')->default('21:00:00');
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('batches');
    }
};
