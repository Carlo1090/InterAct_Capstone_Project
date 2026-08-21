<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One clock-in/clock-out pair. The authoritative record of hours worked.
 *
 * CRITICAL — there is deliberately NO foreign key to batch_students.id.
 * BatchStudentPurgeService hard-deletes archived enrollment rows after 30
 * days, and no table references that id precisely so the purge can never
 * orphan history. A cascading FK here would let the purge silently erase a
 * student's entire attendance record. Keyed off student_id + batch_id
 * instead, exactly like journal_entries and weekly_logs.
 *
 * geofence_id is nullOnDelete so retiring a site never erases the punches
 * taken at it.
 *
 * minutes_worked is STORED rather than derived from the timestamps, so a
 * supervisor's correction (deducting an unlogged lunch break, closing a
 * forgotten punch) is durable and the progress figure cannot drift from what
 * was signed off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dtr_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('batch_id')->constrained('batches')->cascadeOnDelete();
            $table->foreignId('geofence_id')->nullable()->constrained('company_geofences')->nullOnDelete();

            // Derived server-side in config('app.timezone'). Deployments set
            // Asia/Manila; on a UTC box a 07:00 Manila punch would otherwise
            // land on yesterday.
            $table->date('work_date');

            $table->timestamp('time_in');
            $table->decimal('time_in_lat', 10, 7);
            $table->decimal('time_in_lng', 10, 7);
            $table->unsignedSmallInteger('time_in_accuracy')->nullable();
            $table->unsignedSmallInteger('time_in_distance')->nullable();

            $table->timestamp('time_out')->nullable();
            $table->decimal('time_out_lat', 10, 7)->nullable();
            $table->decimal('time_out_lng', 10, 7)->nullable();
            $table->unsignedSmallInteger('time_out_accuracy')->nullable();
            $table->unsignedSmallInteger('time_out_distance')->nullable();

            $table->unsignedSmallInteger('minutes_worked')->nullable();
            $table->enum('status', ['open', 'closed', 'flagged', 'void'])->default('open');

            /**
             * DB-level enforcement of "at most one OPEN session per student".
             *
             * Holds the student_id while the session is open and NULL once it
             * closes. Both MySQL and SQLite treat NULLs as distinct in a unique
             * index, so any number of closed sessions coexist while a second
             * OPEN one for the same student is rejected by the database.
             *
             * The service checks this too, and that check produces the friendly
             * message — but a check-then-write cannot survive two concurrent
             * requests (a double-tap, or two tabs): both read "no open session"
             * before either writes. Verified: without this index the database
             * happily accepted two open sessions for one student.
             *
             * "at most one open per student" is not expressible as a plain
             * composite unique index, and partial/filtered indexes are not
             * portable across MySQL and SQLite — hence the nullable-key trick.
             */
            $table->unsignedBigInteger('open_session_key')->nullable()->unique();

            $table->foreignId('adjusted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('adjustment_reason')->nullable();

            $table->timestamps();

            $table->index(['student_id', 'batch_id', 'work_date']);
            $table->index(['student_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dtr_sessions');
    }
};
