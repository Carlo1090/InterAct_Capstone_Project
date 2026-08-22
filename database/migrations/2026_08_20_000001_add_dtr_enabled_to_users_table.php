<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The OJT coordinator's DTR preference, chosen during account setup.
 *
 * Some departments place interns somewhere without a fixed workplace (field
 * work, rotating hotel assignments), so a location-anchored DTR cannot apply
 * to them. The switch lives on the coordinator rather than the department
 * because coordinator_departments is a model-less pivot reached only through
 * belongsToMany — a payload column there would need withPivot() at every read.
 *
 * Resolution is one hop: batch_students.batch_id -> batches.coordinator_id ->
 * users.dtr_enabled. batches.coordinator_id is NOT NULL and singular, so a
 * department with several coordinators is never ambiguous.
 *
 * Defaults to false, so deploying this changes nothing until a coordinator
 * opts in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('dtr_enabled')->default(false)->after('program_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('dtr_enabled');
        });
    }
};
