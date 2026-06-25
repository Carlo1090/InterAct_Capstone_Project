<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weekly_activity_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('weekly_activity_log_id')->constrained('weekly_activity_logs')->cascadeOnDelete();
            $table->date('inclusive_date_start');
            $table->date('inclusive_date_end');
            $table->text('activities');
            $table->text('documents_records')->nullable();
            $table->text('objectives')->nullable();
            $table->string('supervisor_name', 150)->nullable();
            $table->string('supervisor_position', 100)->nullable();
            $table->tinyInteger('sort_order')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weekly_activity_entries');
    }
};
