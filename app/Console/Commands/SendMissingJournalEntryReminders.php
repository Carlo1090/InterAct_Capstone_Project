<?php

namespace App\Console\Commands;

use App\Models\Batch;
use App\Models\JournalEntry;
use App\Models\Notification as NotificationRecord;
use App\Models\User;
use App\Notifications\MissingJournalEntryReminder;
use App\Support\BatchWorkingDays;
use App\Support\ReminderSchedule;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Nudges enrolled students about every daily journal entry they still owe for
 * the CURRENT WEEK — one message per trigger listing all missing days, not one
 * message per day. The dedupe below allows at most one contact per student per
 * day, so the message has to carry the whole backlog or the rest goes unsaid.
 *
 * Scheduled HOURLY (routes/console.php) rather than once a day, because each
 * student may choose their own reminder hour — the command itself decides whose
 * hour it currently is. Pass --ignore-time for a manual or demo run.
 */
class SendMissingJournalEntryReminders extends Command
{
    private const TITLE = 'Missing Journal Entry Reminder';

    protected $signature = 'journal:send-missing-entry-reminders
                            {--ignore-time : Ignore each student\'s preferred hour and consider everyone due now (manual/demo runs)}';

    protected $description = 'Notify enrolled students who have not submitted a daily journal entry for today.';

    public function handle(): int
    {
        $now = Carbon::now();
        $today = $now->copy()->startOfDay();
        $ignoreTime = (bool) $this->option('ignore-time');

        $batches = Batch::where('is_active', true)
            ->where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->with('batchStudents.student.studentProfile')
            ->get();

        $reminded = 0;
        $emailed = 0;
        $skippedSubmitted = 0;
        $notScheduled = 0;
        $notTheirHour = 0;
        $alreadyReminded = 0;

        foreach ($batches as $batch) {
            foreach ($batch->batchStudents->where('status', 'active') as $enrollment) {
                $student = $enrollment->student;

                if (! $student) {
                    continue;
                }

                $profile = $student->studentProfile;

                // Day gate + on/off switch. A student who set their own days owns
                // this answer (weekends included); otherwise it falls back to the
                // batch's working-day pattern, i.e. the previous behaviour.
                if (! ReminderSchedule::remindsOn($today, $profile, $batch->working_days_per_week)) {
                    $notScheduled++;

                    continue;
                }

                // Hour gate. Counted and reported rather than silently skipped —
                // the old per-batch weekend `continue` printed nothing at all,
                // which made an empty run impossible to interpret.
                if (! $ignoreTime && ReminderSchedule::hourFor($profile, $batch->daily_reminder_time) !== $now->hour) {
                    $notTheirHour++;

                    continue;
                }

                // The whole week's backlog, not just today — one trigger sends
                // one message covering every day still owed (the dedupe below
                // means there is no second chance to mention the rest).
                $missingDates = $this->missingDatesThisWeek($student->id, $batch, $today);

                if ($missingDates === []) {
                    $skippedSubmitted++;
                    $this->line("Skipped {$this->label($student)}: nothing missing this week.");

                    continue;
                }

                // The notifications table has no entry_date/batch_id column to key
                // on, so dedupe on (user, title, today) — otherwise an hourly
                // schedule, or a manual --ignore-time run, would stack duplicates.
                if ($this->alreadyRemindedToday($student->id, $today)) {
                    $alreadyReminded++;

                    continue;
                }

                // Mail only to an address Google has confirmed the student owns.
                // Everyone else still gets the in-app bell row — a coordinator
                // created student may have no email at all, and that is normal.
                $canEmail = $student->email !== null && $student->email_verified_at !== null;

                if ($canEmail) {
                    $student->notify(new MissingJournalEntryReminder($missingDates, $today->toDateString()));
                    $emailed++;
                }

                NotificationRecord::create([
                    'user_id' => $student->id,
                    'title' => self::TITLE,
                    'message' => MissingJournalEntryReminder::inAppMessage($missingDates, $today->toDateString()),
                    // Report what actually happened. This used to always say
                    // 'email' even when nothing was sent and the student had no
                    // address on file.
                    'type' => $canEmail ? 'email' : 'in_app',
                    'is_read' => false,
                    // Set explicitly rather than leaning on the column's
                    // useCurrent() default, so the value follows Carbon's clock
                    // and the same-day dedupe above stays correct under test.
                    'sent_at' => $now,
                ]);

                $reminded++;
                $this->line(
                    "Reminded {$this->label($student)} about ".count($missingDates).' missing day(s) '.
                    '['.implode(', ', $missingDates).'] ('.($canEmail ? 'email + in-app' : 'in-app only').').'
                );
            }
        }

        $this->info(
            "Done. Reminded: {$reminded} (emailed: {$emailed}), nothing missing this week: {$skippedSubmitted}, ".
            "not a reminder day: {$notScheduled}, not their hour: {$notTheirHour}, already reminded today: {$alreadyReminded}."
        );

        return self::SUCCESS;
    }

    /**
     * Every working day from the later of (this week's Monday, the batch start)
     * through today with no submitted entry.
     *
     * Derived, never queried: journal_entries.status only ever holds draft or
     * submitted, so a "missing" row does not exist. This mirrors exactly how
     * StudentDashboardController::countMissingWorkingDays() and
     * JournalCalendarController::statusFor() decide the same question, so the
     * reminder can never disagree with the number on the student's dashboard.
     *
     * The yardstick is BatchWorkingDays — deliberately NOT ReminderSchedule.
     * A student narrowing their reminder_days must not thereby shrink the list
     * of entries they owe; reminder preferences decide when we nudge, never
     * what counts as missing.
     *
     * @return list<string> Y-m-d, ascending
     */
    private function missingDatesThisWeek(int $studentId, Batch $batch, Carbon $today): array
    {
        $weekStart = $today->copy()->startOfWeek();
        $batchStart = $batch->start_date->copy()->startOfDay();
        $cursor = $batchStart->greaterThan($weekStart) ? $batchStart : $weekStart;

        if ($cursor->gt($today)) {
            return [];
        }

        $submitted = JournalEntry::where('student_id', $studentId)
            ->where('batch_id', $batch->id)
            ->where('status', 'submitted')
            // whereDate on both bounds, never whereBetween: entry_date is a
            // date-cast column and SQLite stores it with a time component,
            // which sorts after a bare upper bound and drops the last day.
            ->whereDate('entry_date', '>=', $cursor->toDateString())
            ->whereDate('entry_date', '<=', $today->toDateString())
            ->pluck('entry_date')
            ->map(fn ($date) => $date->toDateString())
            ->all();

        $missing = [];

        while ($cursor->lte($today)) {
            $date = $cursor->toDateString();

            if (BatchWorkingDays::isWorkingDay($cursor, $batch->working_days_per_week)
                && ! in_array($date, $submitted, true)) {
                $missing[] = $date;
            }

            $cursor->addDay();
        }

        return $missing;
    }

    private function alreadyRemindedToday(int $studentId, Carbon $today): bool
    {
        return NotificationRecord::where('user_id', $studentId)
            ->where('title', self::TITLE)
            ->whereDate('sent_at', $today->toDateString())
            ->exists();
    }

    /**
     * Students are increasingly email-less (username-based accounts), so the old
     * "{$student->email}" log lines printed a bare colon for most of them.
     */
    private function label(User $student): string
    {
        return $student->username ?? $student->email ?? "user #{$student->id}";
    }
}
