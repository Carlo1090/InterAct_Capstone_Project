<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coordinator_departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coordinator_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['coordinator_id', 'department_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coordinator_departments');
    }
};
