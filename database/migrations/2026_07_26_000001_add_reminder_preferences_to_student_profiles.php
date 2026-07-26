<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-student daily-journal reminder preferences — the "alarm" a student sets
 * for themselves, including weekend days when they are genuinely rostered.
 *
 * These are read by EXACTLY ONE consumer, the
 * journal:send-missing-entry-reminders command. Nothing in weekly bundling, the
 * journal calendar, missing-entry counts, coordinator dashboards or any report
 * reads them — deliberately, because the student must never be able to redefine
 * the yardstick their own compliance is measured against.
 *
 * All three are nullable/defaulted so an untouched profile behaves exactly as it
 * did before: reminder_time falls back to the batch's daily_reminder_time, and
 * reminder_days falls back to App\Support\BatchWorkingDays.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_profiles', function (Blueprint $table) {
            // Comma-separated ISO day numbers, 1 (Mon) .. 7 (Sun), e.g. "1,2,3,4,5,6".
            $table->string('reminder_days', 20)->nullable()->after('year_level');
            $table->time('reminder_time')->nullable()->after('reminder_days');
            $table->boolean('reminder_enabled')->default(true)->after('reminder_time');
        });
    }

    public function down(): void
    {
        Schema::table('student_profiles', function (Blueprint $table) {
            $table->dropColumn(['reminder_days', 'reminder_time', 'reminder_enabled']);
        });
    }
};
