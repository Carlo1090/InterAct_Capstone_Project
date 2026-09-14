<?php

namespace App\Observers;

use App\Models\Batch;
use App\Support\BatchWorkingDays;

/**
 * Keeps `working_days_per_week` (a plain day-count, still read by every
 * existing consumer — reminders, the dashboard, the journal calendar,
 * ReminderSchedule's fallback) in step with the real `working_days_start`/
 * `working_days_end` range the coordinator actually picks on the circle
 * picker, so nothing downstream needs to change to keep working.
 *
 * Seeders run under DatabaseSeeder's WithoutModelEvents, which mutes this
 * observer exactly like it mutes UserObserver's username generation — those
 * seeders write working_days_start/working_days_end explicitly instead.
 */
class BatchObserver
{
    public function saving(Batch $batch): void
    {
        $rangeDirty = $batch->isDirty('working_days_start') || $batch->isDirty('working_days_end');
        $countDirty = $batch->isDirty('working_days_per_week');

        if ($rangeDirty && $batch->working_days_start !== null && $batch->working_days_end !== null) {
            $batch->working_days_per_week = BatchWorkingDays::countFromRange(
                $batch->working_days_start,
                $batch->working_days_end,
            );

            return;
        }

        if ($countDirty && $batch->working_days_start === null && $batch->working_days_end === null) {
            [$start, $end] = BatchWorkingDays::rangeFromLegacyCount((int) $batch->working_days_per_week);
            $batch->working_days_start = $start;
            $batch->working_days_end = $end;
        }
    }
}
