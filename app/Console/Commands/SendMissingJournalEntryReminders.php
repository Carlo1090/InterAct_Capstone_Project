<?php

namespace App\Console\Commands;

use App\Models\Batch;
use App\Models\JournalEntry;
use App\Models\Notification as NotificationRecord;
use App\Notifications\MissingJournalEntryReminder;
use App\Support\BatchWorkingDays;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SendMissingJournalEntryReminders extends Command
{
    protected $signature = 'journal:send-missing-entry-reminders';

    protected $description = 'Notify enrolled students who have not submitted a daily journal entry for today.';

    public function handle(): int
    {
        $today = Carbon::today();

        $batches = Batch::where('is_active', true)
            ->where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->with('batchStudents.student')
            ->get();

        $reminded = 0;
        $skipped = 0;

        foreach ($batches as $batch) {
            if (! BatchWorkingDays::isWorkingDay($today, $batch->working_days_per_week)) {
                continue;
            }

            foreach ($batch->batchStudents->where('status', 'active') as $enrollment) {
                $student = $enrollment->student;

                if (! $student) {
                    continue;
                }

                $hasSubmitted = JournalEntry::where('student_id', $student->id)
                    ->whereDate('entry_date', $today->toDateString())
                    ->where('status', 'submitted')
                    ->exists();

                if ($hasSubmitted) {
                    $skipped++;
                    $this->line("Skipped {$student->email}: already submitted for {$today->toDateString()}.");

                    continue;
                }

                $student->notify(new MissingJournalEntryReminder($today->toDateString()));

                NotificationRecord::create([
                    'user_id' => $student->id,
                    'title' => 'Missing Journal Entry Reminder',
                    'message' => "You have not submitted your daily journal entry for {$today->toDateString()}.",
                    'type' => 'email',
                    'is_read' => false,
                ]);

                $reminded++;
                $this->line("Reminded {$student->email} for {$today->toDateString()}.");
            }
        }

        $this->info("Done. Reminded: {$reminded}, Skipped (already submitted): {$skipped}.");

        return self::SUCCESS;
    }
}
