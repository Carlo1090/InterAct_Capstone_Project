<?php

namespace App\Services;

use App\Models\BatchStudent;
use App\Models\JournalEntry;
use App\Models\WeeklyLog;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Auto-compiles each active student's Daily Accomplishment entries into their
 * WeeklyLog narrative. Shared by the Monday-midnight schedule
 * (App\Console\Commands\RunWeeklyBundling) and the admin demo-trigger
 * endpoint so the compilation logic lives in exactly one place.
 */
class WeeklyBundlingService
{
    private const DAY_NAMES = ['MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY', 'SATURDAY', 'SUNDAY'];

    /**
     * Compile the given week (the full Mon-Sun span) for every actively
     * enrolled student and updateOrCreate their WeeklyLog narrative — unless
     * that WeeklyLog has already been submitted, in which case it's left
     * untouched.
     *
     * Weekend days are included ONLY when the student actually submitted an
     * entry that day: the presence of an entry is the signal, never a declared
     * schedule. An intern who genuinely worked a Saturday gets it in their
     * narrative; a Mon-Fri intern's output is unchanged, because days with
     * nothing to show are skipped entirely (see compileNarrative).
     *
     * @return array{week_start: string, week_end: string, compiled: int, skipped_submitted: int}
     */
    public function bundleWeek(string|Carbon $weekStart): array
    {
        $monday = Carbon::parse($weekStart)->startOfWeek(Carbon::MONDAY);
        // WeeklyLog rows are keyed Monday-Sunday, matching WeeklyLogController's
        // existing convention — and now the narrative spans the same range, so
        // the row no longer claims days it structurally could not contain.
        $weekEnd = $monday->copy()->addDays(6);

        $activeEnrollments = BatchStudent::where('status', 'active')->get(['student_id', 'batch_id']);

        $compiled = 0;
        $skippedSubmitted = 0;

        foreach ($activeEnrollments as $enrollment) {
            $entries = JournalEntry::where('student_id', $enrollment->student_id)
                ->where('status', 'submitted')
                // whereDate on both bounds, never whereBetween: entry_date is a
                // date-cast column, and SQLite stores it WITH a time component
                // ("2026-08-02 00:00:00"), which sorts after a bare upper bound
                // of "2026-08-02" and silently drops the last day of the range.
                ->whereDate('entry_date', '>=', $monday->toDateString())
                ->whereDate('entry_date', '<=', $weekEnd->toDateString())
                ->get(['entry_date', 'content']);

            $existing = WeeklyLog::where('student_id', $enrollment->student_id)
                ->where('batch_id', $enrollment->batch_id)
                ->whereDate('week_start', $monday->toDateString())
                ->first();

            if ($existing && $existing->submitted_at !== null) {
                $skippedSubmitted++;

                continue;
            }

            $narrative = $this->compileNarrative($monday, $entries);

            // Update the already-fetched row directly rather than
            // WeeklyLog::updateOrCreate() — its plain-equality match on
            // week_start can miss an existing row under SQLite, where a
            // date-cast column still stores a time component (unlike MySQL,
            // which truncates it), and would insert a duplicate instead.
            if ($existing) {
                $existing->update(['week_end' => $weekEnd->toDateString(), 'narrative' => $narrative]);
            } else {
                WeeklyLog::create([
                    'student_id' => $enrollment->student_id,
                    'batch_id' => $enrollment->batch_id,
                    'week_start' => $monday->toDateString(),
                    'week_end' => $weekEnd->toDateString(),
                    'narrative' => $narrative,
                ]);
            }

            $compiled++;
        }

        return [
            'week_start' => $monday->toDateString(),
            'week_end' => $weekEnd->toDateString(),
            'compiled' => $compiled,
            'skipped_submitted' => $skippedSubmitted,
        ];
    }

    /**
     * "MONDAY\n<text>\n\nTUESDAY\n<text>" for each day of the week that has a
     * submitted entry with non-empty daily_accomplishment text — no time
     * range, and days with nothing to show are silently skipped entirely
     * (no placeholder).
     *
     * This skip is what makes weekend support free: SATURDAY/SUNDAY blocks
     * appear only for a student who actually wrote one.
     *
     * @param  Collection<int, JournalEntry>  $entries
     */
    private function compileNarrative(Carbon $monday, Collection $entries): string
    {
        $byDate = $entries->keyBy(fn (JournalEntry $entry) => $entry->entry_date->toDateString());

        $blocks = [];

        foreach (self::DAY_NAMES as $offset => $dayName) {
            $date = $monday->copy()->addDays($offset)->toDateString();
            $entry = $byDate->get($date);
            $text = trim((string) ($entry?->content['daily_accomplishment'] ?? ''));

            if ($text === '') {
                continue;
            }

            $blocks[] = "{$dayName}\n{$text}";
        }

        return implode("\n\n", $blocks);
    }

    /**
     * The start of the most recently COMPLETED Mon-Sun week as of $now.
     *
     * A week is only complete once its Sunday has passed, so whatever day it
     * is, the answer is always the Monday before this week's Monday. (The old
     * Friday pivot returned the CURRENT week on a Saturday, which is what let
     * the bundle — and its one-way edit lock — land on a student mid-Saturday.)
     */
    public static function mostRecentlyCompletedWeekStart(?Carbon $now = null): Carbon
    {
        return ($now ?? Carbon::now())
            ->copy()
            ->startOfDay()
            ->startOfWeek(Carbon::MONDAY)
            ->subWeek();
    }
}
