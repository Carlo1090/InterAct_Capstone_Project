<?php

namespace Tests\Feature\Console;

use App\Models\JournalEntry;
use App\Models\SystemSetting;
use App\Models\WeeklyLog;
use App\Notifications\MissingJournalEntryReminder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Student\Concerns\EnrollsStudentInBatch;
use Tests\TestCase;

class CronEndpointTest extends TestCase
{
    use EnrollsStudentInBatch;
    use RefreshDatabase;

    private const SECRET = 'test-cron-secret-value';

    /** A Wednesday at 21:00 — a working day at the batch's default reminder hour. */
    private const DUE_WEDNESDAY = '2026-07-08 21:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.cron.secret', self::SECRET);
    }

    private function ping(?string $key = self::SECRET): TestResponse
    {
        return $this->postJson('/api/cron/run'.($key === null ? '' : '?key='.$key));
    }

    /**
     * An unset secret must disable the endpoint outright. Without this guard a
     * blank config value would hash_equals() a blank query param and leave the
     * trigger open on any deployment that forgot to configure it.
     */
    public function test_an_unconfigured_secret_disables_the_endpoint(): void
    {
        Notification::fake();
        config()->set('services.cron.secret', null);

        $this->ping(null)->assertNotFound();
        $this->ping('')->assertNotFound();

        Notification::assertNothingSent();
    }

    public function test_a_wrong_or_missing_key_is_rejected_without_running_anything(): void
    {
        Notification::fake();
        $this->travelTo(Carbon::parse(self::DUE_WEDNESDAY));
        $this->enrolledStudent();

        $this->ping('not-the-secret')->assertNotFound();
        $this->ping(null)->assertNotFound();

        Notification::assertNothingSent();
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_the_secret_is_accepted_in_a_header_as_well_as_the_query_string(): void
    {
        Notification::fake();
        $this->travelTo(Carbon::parse(self::DUE_WEDNESDAY));

        $this->postJson('/api/cron/run', [], ['X-Cron-Key' => self::SECRET])
            ->assertOk()
            ->assertJsonPath('tasks.reminders.exit_code', 0);
    }

    public function test_a_correct_key_runs_the_reminders_and_mails_a_verified_student(): void
    {
        Notification::fake();
        $this->travelTo(Carbon::parse(self::DUE_WEDNESDAY));

        $student = $this->enrolledStudent();

        $this->ping()->assertOk()->assertJsonStructure(['ran_at', 'tasks' => ['reminders']]);

        Notification::assertSentTo($student, MissingJournalEntryReminder::class);
    }

    /**
     * The whole reason this endpoint invokes commands directly instead of
     * calling schedule:run: a free pinger cannot promise the minute it arrives
     * on, and an `hourly()` task would be skipped at any minute but :00.
     */
    public function test_reminders_still_fire_when_the_ping_lands_mid_hour(): void
    {
        Notification::fake();
        $this->travelTo(Carbon::parse('2026-07-08 21:37:00'));

        $student = $this->enrolledStudent();

        $this->ping()->assertOk();

        Notification::assertSentTo($student, MissingJournalEntryReminder::class);
    }

    /**
     * GET is supported because several free cron services cannot send anything
     * else.
     */
    public function test_the_endpoint_answers_a_plain_get(): void
    {
        Notification::fake();
        $this->travelTo(Carbon::parse(self::DUE_WEDNESDAY));

        $this->getJson('/api/cron/run?key='.self::SECRET)->assertOk();
    }

    /**
     * THE load-bearing guard. Bundling overwrites any unsubmitted WeeklyLog
     * draft, and nothing distinguishes an auto-fill from a student's own edit —
     * so an hourly ping must not re-run it and wipe work in progress.
     */
    public function test_bundling_runs_once_a_week_and_does_not_clobber_a_draft_on_later_pings(): void
    {
        Notification::fake();
        $this->travelTo(Carbon::parse(self::DUE_WEDNESDAY));

        $student = $this->enrolledStudent();
        $batchId = $student->batchEnrollment->batch_id;

        $this->ping()->assertOk()->assertJsonStructure(['tasks' => ['bundling']]);

        // The student then edits their own draft narrative by hand.
        $weekStart = Carbon::parse(self::DUE_WEDNESDAY)->startOfWeek()->subWeek();
        $log = WeeklyLog::updateOrCreate(
            ['student_id' => $student->id, 'batch_id' => $batchId, 'week_start' => $weekStart->toDateString()],
            ['week_end' => $weekStart->copy()->addDays(6)->toDateString(), 'narrative' => 'MY OWN EDIT', 'status' => 'pending']
        );

        // Five more pings across the rest of the day, as a real hourly cron would.
        for ($i = 0; $i < 5; $i++) {
            $this->travel(1)->hours();
            $response = $this->ping()->assertOk();
            $response->assertJsonMissingPath('tasks.bundling');
        }

        $this->assertSame('MY OWN EDIT', $log->fresh()->narrative);
    }

    public function test_bundling_becomes_due_again_the_following_week(): void
    {
        Notification::fake();
        $this->travelTo(Carbon::parse(self::DUE_WEDNESDAY));
        $this->enrolledStudent();

        $this->ping()->assertJsonStructure(['tasks' => ['bundling']]);

        $this->travelTo(Carbon::parse(self::DUE_WEDNESDAY)->addWeek());
        $this->ping()->assertJsonStructure(['tasks' => ['bundling']]);
    }

    public function test_the_purge_runs_at_most_once_a_day(): void
    {
        Notification::fake();
        $this->travelTo(Carbon::parse(self::DUE_WEDNESDAY));
        $this->enrolledStudent();

        $this->ping()->assertJsonStructure(['tasks' => ['purge']]);
        $this->travel(2)->hours();
        $this->ping()->assertJsonMissingPath('tasks.purge');

        $this->travelTo(Carbon::parse(self::DUE_WEDNESDAY)->addDay());
        $this->ping()->assertJsonStructure(['tasks' => ['purge']]);
    }

    /**
     * The response body is retained in the cron provider's execution history,
     * so it must carry counts only — never the per-student log lines.
     */
    public function test_the_response_never_leaks_student_identifiers(): void
    {
        Notification::fake();
        $this->travelTo(Carbon::parse(self::DUE_WEDNESDAY));

        $student = $this->enrolledStudent();

        $body = $this->ping()->assertOk()->getContent();

        $this->assertStringNotContainsString($student->username, $body);
        $this->assertStringNotContainsString((string) $student->email, $body);
        $this->assertStringContainsString('Done.', $body);
    }

    /**
     * A corrupted marker must not wedge a job forever — it should fall back to
     * "due" rather than throwing on Carbon::parse().
     */
    public function test_a_corrupt_marker_does_not_wedge_the_job(): void
    {
        Notification::fake();
        $this->travelTo(Carbon::parse(self::DUE_WEDNESDAY));
        $this->enrolledStudent();

        SystemSetting::create(['key' => 'cron_last_purge_at', 'value' => 'not a date at all']);

        $this->ping()->assertOk()->assertJsonStructure(['tasks' => ['purge']]);
    }

    /**
     * Repeated pings must not re-mail a student, since the reminder command is
     * invoked on every single call with no marker of its own.
     */
    public function test_repeated_pings_never_mail_the_same_student_twice_in_a_day(): void
    {
        Notification::fake();
        $this->travelTo(Carbon::parse(self::DUE_WEDNESDAY));

        $student = $this->enrolledStudent();
        JournalEntry::query()->delete();

        $this->ping()->assertOk();
        $this->ping()->assertOk();
        $this->ping()->assertOk();

        Notification::assertSentToTimes($student, MissingJournalEntryReminder::class, 1);
    }
}
