<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('batch_students', function (Blueprint $table) {
            $table->foreignId('company_supervisor_id')
                ->nullable()
                ->after('supervisor_id')
                ->constrained('company_supervisors')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('batch_students', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_supervisor_id');
        });
    }
};
