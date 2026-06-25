<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('batch_id')->constrained('batches')->cascadeOnDelete();
            $table->date('entry_date');
            $table->json('content'); // Section-based content keyed to template sections
            $table->enum('status', ['draft', 'submitted', 'overdue', 'missing'])->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['student_id', 'entry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
