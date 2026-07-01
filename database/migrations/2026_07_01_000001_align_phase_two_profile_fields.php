<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'student_id_number')) {
                $table->string('student_id_number', 30)->nullable()->unique()->after('role');
            }
        });

        Schema::table('student_profiles', function (Blueprint $table): void {
            if (! Schema::hasColumn('student_profiles', 'company_name')) {
                $table->string('company_name', 150)->nullable()->after('user_id');
            }

            if (! Schema::hasColumn('student_profiles', 'address')) {
                $table->text('address')->nullable()->after('company_name');
            }

            if (! Schema::hasColumn('student_profiles', 'total_hours_required')) {
                $table->integer('total_hours_required')->nullable()->after('address');
            }
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE student_profiles MODIFY student_id_number VARCHAR(30) NULL');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE student_profiles MODIFY student_id_number VARCHAR(30) NOT NULL');
        }

        Schema::table('student_profiles', function (Blueprint $table): void {
            if (Schema::hasColumn('student_profiles', 'total_hours_required')) {
                $table->dropColumn('total_hours_required');
            }

            if (Schema::hasColumn('student_profiles', 'address')) {
                $table->dropColumn('address');
            }

            if (Schema::hasColumn('student_profiles', 'company_name')) {
                $table->dropColumn('company_name');
            }
        });

        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'student_id_number')) {
                $table->dropUnique(['student_id_number']);
                $table->dropColumn('student_id_number');
            }
        });
    }
};
