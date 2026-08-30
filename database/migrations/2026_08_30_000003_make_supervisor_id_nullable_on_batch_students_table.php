<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A coordinator-centered batch has NO company supervisor at all — not a
 * different one, none — so its enrollments have nobody to pin.
 *
 * `batch_students.supervisor_id` has been NOT NULL since the beginning, which
 * is what made "the supervisor is tied to the company, never manually picked"
 * enforceable at the database level. That invariant is unchanged for
 * supervisor-supported batches: EnrollmentService still resolves the company's
 * login and still 422s when there is none. This migration only makes room for
 * the case where the question does not arise.
 *
 * The null is also what keeps supervisor scoping honest with one clause:
 * ScopesSupervisorWork::supervisedEnrollments() filters on a non-null
 * supervisor_id, so a coordinator-centered enrollment can never appear on a
 * supervisor's roster, queue, notebook or time-record page even when the same
 * company also hosts a supervisor-supported batch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('batch_students', function (Blueprint $table) {
            $table->foreignId('supervisor_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('batch_students', function (Blueprint $table) {
            $table->foreignId('supervisor_id')->nullable(false)->change();
        });
    }
};
