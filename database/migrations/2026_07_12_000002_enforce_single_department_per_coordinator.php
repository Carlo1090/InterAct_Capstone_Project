<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coordinator_departments', function (Blueprint $table) {
            $table->unique('coordinator_id');
            $table->dropUnique(['coordinator_id', 'department_id']);
        });
    }

    public function down(): void
    {
        Schema::table('coordinator_departments', function (Blueprint $table) {
            $table->unique(['coordinator_id', 'department_id']);
            $table->dropUnique(['coordinator_id']);
        });
    }
};
