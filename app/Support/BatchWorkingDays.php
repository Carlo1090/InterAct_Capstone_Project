<?php

namespace App\Support;

use Carbon\CarbonInterface;

class BatchWorkingDays
{
    /**
     * Legacy count-only check, kept as-is for any caller that still only has
     * a bare working_days_per_week (none exist in app/ today, but the method
     * stays exact so nothing standalone silently changes behavior).
     */
    public static function isWorkingDay(CarbonInterface $date, int $workingDaysPerWeek): bool
    {
        $dayOfWeek = $date->dayOfWeekIso; // 1 (Mon) ... 7 (Sun)

        if ($workingDaysPerWeek >= 7) {
            return true;
        }

        if ($workingDaysPerWeek === 6) {
            return $dayOfWeek !== 7;
        }

        return $dayOfWeek < 6;
    }

    /**
     * Whether $date falls within the inclusive [start, end] ISO weekday range,
     * wrapping across the week when end precedes start (e.g. Sat(6) -> Tue(2)
     * covers Sat, Sun, Mon, Tue). This is what actually makes an arbitrary
     * start+end day meaningful — a caller that only checks a day COUNT (see
     * isWorkingDay() above) has no way to represent a range that doesn't start
     * Monday.
     */
    public static function isWorkingDayInRange(CarbonInterface $date, int $start, int $end): bool
    {
        $iso = $date->dayOfWeekIso; // 1 (Mon) ... 7 (Sun)

        return $start <= $end
            ? ($iso >= $start && $iso <= $end)
            : ($iso >= $start || $iso <= $end);
    }

    /**
     * The exact mapping BatchWorkingDays::isWorkingDay() has always assumed:
     * every range starts Monday, and only the LENGTH varied. Used to backfill
     * working_days_start/end for a batch that only ever specified a count
     * (the migration's backfill, and BatchObserver for any caller still
     * posting the legacy working_days_per_week field).
     *
     * @return array{0: int, 1: int} [start, end]
     */
    public static function rangeFromLegacyCount(int $count): array
    {
        return match (true) {
            $count >= 7 => [1, 7],
            $count === 6 => [1, 6],
            default => [1, 5],
        };
    }

    /**
     * The number of days a [start, end] range spans, wrapping the same way
     * isWorkingDayInRange() does — so a batch's stored working_days_per_week
     * (still read by every existing consumer) stays an honest day-count for
     * whatever range the coordinator actually picked, not just the old
     * Monday-anchored cases.
     */
    public static function countFromRange(int $start, int $end): int
    {
        return $end >= $start ? ($end - $start + 1) : ((7 - $start + 1) + $end);
    }
}
