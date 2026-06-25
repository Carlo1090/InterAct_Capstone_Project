<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weekly_log_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('weekly_log_id')->constrained('weekly_logs')->cascadeOnDelete();
            $table->foreignId('journal_entry_id')->constrained('journal_entries')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weekly_log_entries');
    }
};
