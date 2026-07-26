<?php

namespace Tests\Feature\Console;

use App\Models\JournalEntry;
use App\Models\SystemSetting;
use App\Notifications\MissingJournalEntryReminder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Student\Concerns\EnrollsStudentInBatch;
use Tests\TestCase;

class SendMissingJournalEntryRemindersTest extends TestCase
{
    use EnrollsStudentInBatch;
    use RefreshDatabase;

    /**
     * A Wednesday at 21:00 — a working day for a default 5-day batch, at the
     * batch's default daily_reminder_time.
     *
     * The clock is pinned rather than left at the real "now" because these tests
     * used to fail every weekend: the command skips non-working days, so running
     * the suite on a Saturday made an assertion about reminders being sent
     * unsatisfiable. Now the schedule under test is explicit.
     */
    private const DUE_WEDNESDAY = '2026-07-08 21:00:00';

    private const SATURDAY = '2026-08-01 21:00:00';

    public function test_it_reminds_students_with_no_submitted_entry_today_and_skips_the_rest(): void
    {
        Notification::fake();
        $this->travelTo(Carbon::parse(self::DUE_WEDNESDAY));

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

        // The factory sets email_verified_at, so this student is mailable.
        $this->assertDatabaseHas('notifications', [
            'user_id' => $studentWithNoEntry->id,
            'type' => 'email',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $studentWhoSubmitted->id,
        ]);
    }

    public function test_it_skips_a_non_working_day_when_the_student_has_no_preference(): void
    {
        Notification::fake();
        // 21:00 on a Saturday: the hour is right, so only the DAY gate can be
        // what stops the reminder.
        $this->travelTo(Carbon::parse(self::SATURDAY));

        $student = $this->enrolledStudent(['working_days_per_week' => 5]);

        $this->artisan('journal:send-missing-entry-reminders')->assertSuccessful();

        Notification::assertNotSentTo($student, MissingJournalEntryReminder::class);
        $this->assertDatabaseMissing('notifications', ['user_id' => $student->id]);
    }

    /**
     * The feature this was built for: an intern genuinely rostered on Saturdays
     * opts into a Saturday reminder even though their batch is a 5-day batch.
     */
    public function test_a_student_who_opts_into_saturday_is_reminded_on_saturday(): void
    {
        Notification::fake();
        $this->travelTo(Carbon::parse(self::SATURDAY));

        $student = $this->enrolledStudent(['working_days_per_week' => 5]);
        $student->studentProfile()->update(['reminder_days' => '1,2,3,4,5,6']);

        $this->artisan('journal:send-missing-entry-reminders')->assertSuccessful();

        Notification::assertSentTo($student, MissingJournalEntryReminder::class);
        $this->assertDatabaseHas('notifications', ['user_id' => $student->id]);
    }

    public function test_disabling_reminders_stops_them_entirely(): void
    {
        Notification::fake();
        $this->travelTo(Carbon::parse(self::DUE_WEDNESDAY));

        $student = $this->enrolledStudent();
        $student->studentProfile()->update(['reminder_enabled' => false]);

        $this->artisan('journal:send-missing-entry-reminders')->assertSuccessful();

        Notification::assertNotSentTo($student, MissingJournalEntryReminder::class);
        $this->assertDatabaseMissing('notifications', ['user_id' => $student->id]);
    }

    public function test_a_students_own_reminder_hour_wins_over_the_batch_default(): void
    {
        Notification::fake();
        // 06:00 on a working day. The batch default is 21:00, so nothing should
        // fire for a student without a preference...
        $this->travelTo(Carbon::parse('2026-07-08 06:00:00'));

        $defaultStudent = $this->enrolledStudent();
        $earlyBird = $this->enrolledStudent();
        $earlyBird->studentProfile()->update(['reminder_time' => '06:00:00']);

        $this->artisan('journal:send-missing-entry-reminders')->assertSuccessful();

        Notification::assertNotSentTo($defaultStudent, MissingJournalEntryReminder::class);
        Notification::assertSentTo($earlyBird, MissingJournalEntryReminder::class);
    }

    /**
     * The schedule is hourly now, so without a guard a student would be nagged
     * once an hour for the rest of the day.
     */
    public function test_it_does_not_remind_the_same_student_twice_in_one_day(): void
    {
        Notification::fake();
        $this->travelTo(Carbon::parse(self::DUE_WEDNESDAY));

        $student = $this->enrolledStudent();

        $this->artisan('journal:send-missing-entry-reminders')->assertSuccessful();
        $this->artisan('journal:send-missing-entry-reminders', ['--ignore-time' => true])->assertSuccessful();

        $this->assertSame(1, \App\Models\Notification::where('user_id', $student->id)->count());
    }

    /**
     * A coordinator-created student typically has no email at all, and an
     * unverified address must never be mailed. They still get the in-app row —
     * and its type must say so rather than claiming an email went out.
     */
    public function test_an_unverified_student_gets_an_in_app_row_and_no_mail(): void
    {
        Notification::fake();
        $this->travelTo(Carbon::parse(self::DUE_WEDNESDAY));

        $student = $this->enrolledStudent();
        $student->forceFill(['email' => null, 'email_verified_at' => null])->save();

        $this->artisan('journal:send-missing-entry-reminders')->assertSuccessful();

        Notification::assertNotSentTo($student, MissingJournalEntryReminder::class);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $student->id,
            'type' => 'in_app',
        ]);
    }

    /**
     * The admin's System Settings page has always collected a "System Email" and
     * nothing read it. Reminder mail now sends from it when set.
     */
    public function test_the_system_email_setting_is_used_as_the_sender(): void
    {
        Notification::fake();
        $this->travelTo(Carbon::parse(self::DUE_WEDNESDAY));

        SystemSetting::create(['key' => 'system_email', 'value' => 'sipp@materdei.edu.ph']);

        $student = $this->enrolledStudent();

        $this->artisan('journal:send-missing-entry-reminders')->assertSuccessful();

        Notification::assertSentTo($student, MissingJournalEntryReminder::class, function ($notification) use ($student) {
            $mail = $notification->toMail($student);

            return $mail->from === ['sipp@materdei.edu.ph', config('app.name')];
        });
    }

    public function test_a_malformed_system_email_is_ignored_rather_than_handed_to_the_transport(): void
    {
        Notification::fake();
        $this->travelTo(Carbon::parse(self::DUE_WEDNESDAY));

        SystemSetting::create(['key' => 'system_email', 'value' => 'not an address']);

        $student = $this->enrolledStudent();

        $this->artisan('journal:send-missing-entry-reminders')->assertSuccessful();

        Notification::assertSentTo($student, MissingJournalEntryReminder::class, function ($notification) use ($student) {
            return $notification->toMail($student)->from === [];
        });
    }

    public function test_an_address_on_file_but_unverified_is_still_not_mailed(): void
    {
        Notification::fake();
        $this->travelTo(Carbon::parse(self::DUE_WEDNESDAY));

        $student = $this->enrolledStudent();
        $student->forceFill(['email_verified_at' => null])->save();

        $this->artisan('journal:send-missing-entry-reminders')->assertSuccessful();

        Notification::assertNotSentTo($student, MissingJournalEntryReminder::class);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $student->id,
            'type' => 'in_app',
        ]);
    }
}
