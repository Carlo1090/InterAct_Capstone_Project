<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_supervisors', function (Blueprint $table) {
            $table->string('name', 150)->nullable()->after('user_id');
        });

        Schema::table('company_supervisors', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
            $table->string('position', 100)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('company_supervisors', function (Blueprint $table) {
            $table->dropColumn('name');
        });

        Schema::table('company_supervisors', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable(false)->change();
            $table->string('position', 100)->nullable(false)->change();
        });
    }
};
