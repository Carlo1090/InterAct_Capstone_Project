<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which hardcoded exit interview form a department uses, and which one an
 * interview was answered under.
 *
 * Both are plain string keys into App\Support\ExitInterview\ExitInterviewForms
 * — not a foreign key, because the forms are code, not rows. Both default to
 * 'cabm', the original form, so every department and every interview that
 * already exists keeps exactly the behaviour it had; the only backfill is
 * CAST, whose own form ships in the same change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            // Chosen by the admin when the department is created, changeable
            // on edit. Never null: a department with no answer here would
            // leave its students with no form at all.
            $table->string('exit_interview_form', 20)->default('cabm')->after('dean_name');
        });

        Schema::table('student_exit_interviews', function (Blueprint $table) {
            // Snapshotted from the batch's department when the interview row
            // is first written, so a finished form keeps rendering under the
            // questions it actually answered even if the department's
            // assignment later changes. Every interview that exists at this
            // point was answered on the CABM form — it was the only one.
            $table->string('form_key', 20)->default('cabm')->after('batch_id');
        });

        DB::table('departments')->where('code', 'CAST')->update(['exit_interview_form' => 'cast']);
    }

    public function down(): void
    {
        Schema::table('student_exit_interviews', function (Blueprint $table) {
            $table->dropColumn('form_key');
        });

        Schema::table('departments', function (Blueprint $table) {
            $table->dropColumn('exit_interview_form');
        });
    }
};
