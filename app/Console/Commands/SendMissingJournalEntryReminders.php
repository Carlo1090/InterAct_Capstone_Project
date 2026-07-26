<?php

namespace App\Console\Commands;

use App\Models\Batch;
use App\Models\JournalEntry;
use App\Models\Notification as NotificationRecord;
use App\Models\User;
use App\Notifications\MissingJournalEntryReminder;
use App\Support\ReminderSchedule;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Nudges enrolled students who have not submitted today's daily journal entry.
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

                $hasSubmitted = JournalEntry::where('student_id', $student->id)
                    ->whereDate('entry_date', $today->toDateString())
                    ->where('status', 'submitted')
                    ->exists();

                if ($hasSubmitted) {
                    $skippedSubmitted++;
                    $this->line("Skipped {$this->label($student)}: already submitted for {$today->toDateString()}.");

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
                    $student->notify(new MissingJournalEntryReminder($today->toDateString()));
                    $emailed++;
                }

                NotificationRecord::create([
                    'user_id' => $student->id,
                    'title' => self::TITLE,
                    'message' => "You have not submitted your daily journal entry for {$today->toDateString()}.",
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
                $this->line("Reminded {$this->label($student)} for {$today->toDateString()} (".($canEmail ? 'email + in-app' : 'in-app only').').');
            }
        }

        $this->info(
            "Done. Reminded: {$reminded} (emailed: {$emailed}), already submitted: {$skippedSubmitted}, ".
            "not a reminder day: {$notScheduled}, not their hour: {$notTheirHour}, already reminded today: {$alreadyReminded}."
        );

        return self::SUCCESS;
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
