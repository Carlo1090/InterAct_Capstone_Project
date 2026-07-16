<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('batch_students', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('batch_students', function (Blueprint $table) {
            $table->dropColumn('archived_at');
        });
    }
};
