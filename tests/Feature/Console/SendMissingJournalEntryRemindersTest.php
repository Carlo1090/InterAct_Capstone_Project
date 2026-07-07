<?php

namespace Tests\Feature\Console;

use App\Models\JournalEntry;
use App\Notifications\MissingJournalEntryReminder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Student\Concerns\EnrollsStudentInBatch;
use Tests\TestCase;

class SendMissingJournalEntryRemindersTest extends TestCase
{
    use RefreshDatabase;
    use EnrollsStudentInBatch;

    public function test_it_reminds_students_with_no_submitted_entry_today_and_skips_the_rest(): void
    {
        Notification::fake();

        $studentWithNoEntry = $this->enrolledStudent();
        $studentWhoSubmitted = $this->enrolledStudent();

        JournalEntry::create([
            'student_id' => $studentWhoSubmitted->id,
            'batch_id' => $studentWhoSubmitted->batchEnrollment->batch_id,
            'entry_date' => now()->toDateString(),
            'content' => ['Tasks Performed' => 'Already submitted today.'],
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        $this->artisan('journal:send-missing-entry-reminders')->assertSuccessful();

        Notification::assertSentTo($studentWithNoEntry, MissingJournalEntryReminder::class);
        Notification::assertNotSentTo($studentWhoSubmitted, MissingJournalEntryReminder::class);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $studentWithNoEntry->id,
            'type' => 'email',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $studentWhoSubmitted->id,
        ]);
    }

    public function test_it_skips_batches_on_a_non_working_day(): void
    {
        Notification::fake();

        $nextSaturday = now();
        while (! $nextSaturday->isWeekend()) {
            $nextSaturday = $nextSaturday->addDay();
        }

        $this->travelTo($nextSaturday);

        $student = $this->enrolledStudent(['working_days_per_week' => 5]);

        $this->artisan('journal:send-missing-entry-reminders')->assertSuccessful();

        Notification::assertNotSentTo($student, MissingJournalEntryReminder::class);

        $this->travelBack();
    }
}
