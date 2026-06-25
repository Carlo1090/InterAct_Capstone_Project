<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edit_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('weekly_log_id')->constrained('weekly_logs')->cascadeOnDelete();
            $table->foreignId('edited_by')->constrained('users')->cascadeOnDelete();
            $table->text('previous_content');
            $table->text('new_content');
            $table->enum('action', ['submitted', 'edited', 'returned', 'approved']);
            $table->timestamp('edited_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edit_history');
    }
};
