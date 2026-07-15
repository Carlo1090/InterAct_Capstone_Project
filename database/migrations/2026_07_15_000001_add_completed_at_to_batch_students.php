<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('batch_students', function (Blueprint $table) {
            $table->timestamp('completed_at')->nullable()->after('enrolled_at');
        });
    }

    public function down(): void
    {
        Schema::table('batch_students', function (Blueprint $table) {
            $table->dropColumn('completed_at');
        });
    }
};
