<?php

namespace App\Support;

use App\Models\StudentProfile;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Resolves WHEN a student should be nudged about a missing daily journal entry,
 * layering the student's own preference over the batch default.
 *
 * Sits beside BatchWorkingDays (same App\Support static-predicate shape). The
 * split matters: BatchWorkingDays answers "was work expected?", which drives
 * compliance, while this class only answers "should we send a reminder?", which
 * is the student's own business. Nothing here may ever feed a missing-entry
 * count — see the migration docblock for why.
 */
class ReminderSchedule
{
    /**
     * Fallback hour when neither the student nor the batch has a time set —
     * matches the batches.daily_reminder_time schema default.
     */
    public const DEFAULT_HOUR = 21;

    /**
     * Should $date be reminded about?
     *
     * A student who has picked their own days owns the answer completely,
     * weekends included — that is the whole point of the feature, since interns
     * are sometimes genuinely rostered on a Saturday. Otherwise we fall back to
     * the batch's working-day pattern, which is exactly today's behaviour.
     */
    public static function remindsOn(CarbonInterface $date, ?StudentProfile $profile, int $workingDaysPerWeek): bool
    {
        if ($profile && $profile->reminder_enabled === false) {
            return false;
        }

        $chosenDays = $profile?->reminderDayNumbers();

        if ($chosenDays !== null) {
            return in_array($date->dayOfWeekIso, $chosenDays, true);
        }

        return BatchWorkingDays::isWorkingDay($date, $workingDaysPerWeek);
    }

    /**
     * The hour (0-23) at which this student's reminder should fire: their own
     * preference, else the batch's daily_reminder_time, else DEFAULT_HOUR.
     *
     * Only the hour is significant — the scheduler runs the command hourly, so
     * minutes cannot be honoured and are deliberately ignored rather than
     * silently rounded.
     */
    public static function hourFor(?StudentProfile $profile, ?string $batchReminderTime): int
    {
        return self::parseHour($profile?->reminder_time)
            ?? self::parseHour($batchReminderTime)
            ?? self::DEFAULT_HOUR;
    }

    /**
     * Tolerates the several shapes a time value arrives in: "21:00",
     * "21:00:00", a full datetime string from SQLite, or null/empty.
     */
    private static function parseHour(mixed $time): ?int
    {
        if ($time === null) {
            return null;
        }

        $raw = trim((string) $time);

        if ($raw === '') {
            return null;
        }

        try {
            return (int) Carbon::parse($raw)->format('G');
        } catch (\Throwable) {
            return null;
        }
    }
}
