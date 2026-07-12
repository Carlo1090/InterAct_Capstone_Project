<?php

namespace App\Services;

use App\Models\BatchStudent;
use App\Models\JournalEntry;
use App\Models\WeeklyLog;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Auto-compiles each active student's Mon-Fri Daily Accomplishment entries
 * into their WeeklyLog narrative. Shared by the Saturday-midnight schedule
 * (App\Console\Commands\RunWeeklyBundling) and the admin demo-trigger
 * endpoint so the compilation logic lives in exactly one place.
 */
class WeeklyBundlingService
{
    private const WEEKDAY_NAMES = ['MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY'];

    /**
     * Compile the given week (Mon-Fri, Sat/Sun ignored) for every actively
     * enrolled student and updateOrCreate their WeeklyLog narrative — unless
     * that WeeklyLog has already been submitted, in which case it's left
     * untouched.
     *
     * @return array{week_start: string, week_end: string, compiled: int, skipped_submitted: int}
     */
    public function bundleWeek(string|Carbon $weekStart): array
    {
        $monday = Carbon::parse($weekStart)->startOfWeek(Carbon::MONDAY);
        $friday = $monday->copy()->addDays(4);
        // WeeklyLog rows are keyed Monday-Sunday (matching WeeklyLogController's
        // existing convention), even though only Mon-Fri feeds the narrative.
        $weekEnd = $monday->copy()->addDays(6);

        $activeEnrollments = BatchStudent::where('status', 'active')->get(['student_id', 'batch_id']);

        $compiled = 0;
        $skippedSubmitted = 0;

        foreach ($activeEnrollments as $enrollment) {
            $entries = JournalEntry::where('student_id', $enrollment->student_id)
                ->where('status', 'submitted')
                ->whereBetween('entry_date', [$monday->toDateString(), $friday->toDateString()])
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
     * "MONDAY\n<text>\n\nTUESDAY\n<text>" for each weekday that has a
     * submitted entry with non-empty daily_accomplishment text — no time
     * range, and days with nothing to show are silently skipped entirely
     * (no placeholder).
     *
     * @param  Collection<int, JournalEntry>  $entries
     */
    private function compileNarrative(Carbon $monday, Collection $entries): string
    {
        $byDate = $entries->keyBy(fn (JournalEntry $entry) => $entry->entry_date->toDateString());

        $blocks = [];

        foreach (self::WEEKDAY_NAMES as $offset => $dayName) {
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
     * The most recently COMPLETED Mon-Fri as of $now: if today is Saturday
     * or Sunday, that's this week's Monday; otherwise (Mon-Fri, mid-week)
     * last week's Friday hasn't been superseded yet, so it's last week's
     * Monday.
     */
    public static function mostRecentlyCompletedWeekStart(?Carbon $now = null): Carbon
    {
        $today = ($now ?? Carbon::now())->copy()->startOfDay();
        $monday = $today->copy()->startOfWeek(Carbon::MONDAY);
        $friday = $monday->copy()->addDays(4);

        return $today->greaterThan($friday) ? $monday : $monday->copy()->subWeek();
    }
}
