<?php

namespace Tests\Feature\Console;

use App\Models\JournalEntry;
use App\Models\SystemSetting;
use App\Models\User;
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

    /** Monday, Tuesday and Wednesday of DUE_WEDNESDAY's week. */
    private const WEEK_SO_FAR = ['2026-07-06', '2026-07-07', '2026-07-08'];

    /**
     * "Up to date" means the whole week, not just today: the reminder covers
     * every day still owed since Monday, so a student is only skipped once
     * there is nothing left missing at all.
     */
    public function test_it_reminds_students_with_a_missing_entry_and_skips_those_up_to_date(): void
    {
        Notification::fake();
        $this->travelTo(Carbon::parse(self::DUE_WEDNESDAY));

        $studentWithNoEntry = $this->enrolledStudent();
        $studentWhoSubmitted = $this->enrolledStudent();

        foreach (self::WEEK_SO_FAR as $date) {
            $this->submitEntry($studentWhoSubmitted, $date);
        }

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

    /**
     * The point of the feature: every day still owed this week arrives in ONE
     * message, because the same-day dedupe means there is no second send.
     */
    public function test_every_missing_day_of_the_week_arrives_in_a_single_message(): void
    {
        Notification::fake();
        $this->travelTo(Carbon::parse(self::DUE_WEDNESDAY));

        $student = $this->enrolledStudent();

        $this->artisan('journal:send-missing-entry-reminders')->assertSuccessful();

        Notification::assertSentTimes(MissingJournalEntryReminder::class, 1);
        Notification::assertSentTo($student, MissingJournalEntryReminder::class, function ($notification) use ($student) {
            $mail = $notification->toMail($student);
            $body = implode("\n", $mail->introLines);

            return $mail->subject === '[InternTrack] You have 3 missing journal entries this week'
                && str_contains($body, 'today, Wednesday, July 8, 2026')
                && str_contains($body, '2 earlier days this week are also still missing')
                && str_contains($body, '• Monday, July 6, 2026')
                && str_contains($body, '• Tuesday, July 7, 2026');
        });

        // The in-app bell row names the same days.
        $this->assertDatabaseHas('notifications', [
            'user_id' => $student->id,
            'message' => 'You have 3 missing journal entries this week: Jul 6, Jul 7, Jul 8.',
        ]);
    }

    public function test_a_single_missing_day_reads_in_the_singular(): void
    {
        Notification::fake();
        $this->travelTo(Carbon::parse(self::DUE_WEDNESDAY));

        $student = $this->enrolledStudent();
        $this->submitEntry($student, '2026-07-06');
        $this->submitEntry($student, '2026-07-07');

        $this->artisan('journal:send-missing-entry-reminders')->assertSuccessful();

        Notification::assertSentTo($student, MissingJournalEntryReminder::class, function ($notification) use ($student) {
            $mail = $notification->toMail($student);
            $body = implode("\n", $mail->introLines);

            return $mail->subject === '[InternTrack] Missing journal entry for today'
                && str_contains($body, 'today, Wednesday, July 8, 2026')
                && ! str_contains($body, 'earlier day')
                && str_contains($body, 'Please write it up');
        });

        $this->assertDatabaseHas('notifications', [
            'user_id' => $student->id,
            'message' => 'You have not submitted your daily journal entry for Jul 8, 2026.',
        ]);
    }

    /**
     * Today submitted but earlier days blank. The old command skipped this
     * student outright, so the backlog was never mentioned at all.
     */
    public function test_a_student_up_to_date_today_is_still_reminded_about_earlier_days(): void
    {
        Notification::fake();
        $this->travelTo(Carbon::parse(self::DUE_WEDNESDAY));

        $student = $this->enrolledStudent();
        $this->submitEntry($student, '2026-07-08');

        $this->artisan('journal:send-missing-entry-reminders')->assertSuccessful();

        Notification::assertSentTo($student, MissingJournalEntryReminder::class, function ($notification) use ($student) {
            $mail = $notification->toMail($student);
            $body = implode("\n", $mail->introLines);

            return $mail->subject === '[InternTrack] You have 2 missing journal entries this week'
                && str_contains($body, 'You are up to date for today, but 2 earlier days this week are still missing')
                && str_contains($body, '• Monday, July 6, 2026')
                && str_contains($body, '• Tuesday, July 7, 2026')
                && ! str_contains($body, 'July 8');
        });
    }

    /**
     * Reminder preferences decide WHEN a student is nudged, never what counts
     * as missing — otherwise narrowing reminder_days would quietly shrink the
     * backlog a student owes. The yardstick stays the batch's working days.
     */
    public function test_narrowing_reminder_days_does_not_shrink_the_missing_list(): void
    {
        Notification::fake();
        $this->travelTo(Carbon::parse(self::DUE_WEDNESDAY));

        $student = $this->enrolledStudent();
        // Wednesday only — yet Monday and Tuesday are still owed.
        $student->studentProfile()->update(['reminder_days' => '3']);

        $this->artisan('journal:send-missing-entry-reminders')->assertSuccessful();

        Notification::assertSentTo($student, MissingJournalEntryReminder::class, function ($notification) use ($student) {
            return $notification->toMail($student)->subject === '[InternTrack] You have 3 missing journal entries this week';
        });
    }

    /**
     * A non-working day is never owed, so it can never appear in the backlog —
     * even for a student who opted into being reminded on it.
     */
    public function test_a_weekend_is_never_counted_as_a_missing_day(): void
    {
        Notification::fake();
        $this->travelTo(Carbon::parse(self::SATURDAY));

        $student = $this->enrolledStudent(['working_days_per_week' => 5]);
        $student->studentProfile()->update(['reminder_days' => '1,2,3,4,5,6']);

        $this->artisan('journal:send-missing-entry-reminders')->assertSuccessful();

        // Mon 27 Jul .. Fri 31 Jul are owed; Saturday 1 Aug itself is not.
        Notification::assertSentTo($student, MissingJournalEntryReminder::class, function ($notification) use ($student) {
            $mail = $notification->toMail($student);

            return $mail->subject === '[InternTrack] You have 5 missing journal entries this week'
                && ! str_contains(implode("\n", $mail->introLines), 'August 1');
        });
    }

    private function submitEntry(User $student, string $date): void
    {
        JournalEntry::create([
            'student_id' => $student->id,
            'batch_id' => $student->batchEnrollment->batch_id,
            'entry_date' => $date,
            'content' => ['Tasks Performed' => 'Submitted.'],
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);
    }
}
