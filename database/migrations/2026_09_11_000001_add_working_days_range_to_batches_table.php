<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces the coordinator's plain "how many days a week" number input with a
 * real day-of-week RANGE, picked via a circle picker (M T W T F S S) that can
 * start and end on any day, wrapping across the week if needed (e.g. Sat->Tue).
 *
 * `working_days_per_week` stays — every existing consumer (reminders, the
 * dashboard, the journal calendar, ReminderSchedule's fallback) still reads
 * it, now kept in sync automatically by BatchObserver on every save rather
 * than being the thing the coordinator types directly.
 *
 * The backfill below freezes the EXACT mapping BatchWorkingDays::isWorkingDay()
 * already assumed (7 -> every day, 6 -> Mon-Sat, anything else -> Mon-Fri), so
 * every existing batch behaves identically the moment this migration runs —
 * this is a UI/schema change, not a behaviour change for batches that already
 * exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            $table->tinyInteger('working_days_start')->nullable()->after('working_days_per_week');
            $table->tinyInteger('working_days_end')->nullable()->after('working_days_start');
        });

        DB::table('batches')->orderBy('id')->select('id', 'working_days_per_week')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    $count = (int) $row->working_days_per_week;
                    [$start, $end] = match (true) {
                        $count >= 7 => [1, 7],
                        $count === 6 => [1, 6],
                        default => [1, 5],
                    };

                    DB::table('batches')->where('id', $row->id)
                        ->update(['working_days_start' => $start, 'working_days_end' => $end]);
                }
            });

        Schema::table('batches', function (Blueprint $table) {
            $table->tinyInteger('working_days_start')->nullable(false)->change();
            $table->tinyInteger('working_days_end')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            $table->dropColumn(['working_days_start', 'working_days_end']);
        });
    }
};
