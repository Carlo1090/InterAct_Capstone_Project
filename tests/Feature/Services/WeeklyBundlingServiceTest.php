<?php

namespace Tests\Feature\Services;

use App\Models\JournalEntry;
use App\Models\WeeklyLog;
use App\Services\WeeklyBundlingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Student\Concerns\EnrollsStudentInBatch;
use Tests\TestCase;

class WeeklyBundlingServiceTest extends TestCase
{
    use EnrollsStudentInBatch;
    use RefreshDatabase;

    private function entry(int $studentId, int $batchId, string $date, string $status, ?string $dailyAccomplishment = null): JournalEntry
    {
        return JournalEntry::create([
            'student_id' => $studentId,
            'batch_id' => $batchId,
            'entry_date' => $date,
            'content' => $dailyAccomplishment !== null ? ['daily_accomplishment' => $dailyAccomplishment] : ['daily_accomplishment' => ''],
            'status' => $status,
            'submitted_at' => $status === 'submitted' ? now() : null,
        ]);
    }

    /**
     * whereDate(), not where(), because a date-cast column under SQLite
     * still carries a time component in storage (unlike MySQL, which
     * truncates it) — plain equality against a bare Y-m-d string would miss.
     */
    private function weeklyLogFor(int $studentId, Carbon $weekStart): ?WeeklyLog
    {
        return WeeklyLog::where('student_id', $studentId)->whereDate('week_start', $weekStart->toDateString())->first();
    }

    public function test_compiles_narrative_with_day_headers_and_no_time_range(): void
    {
        $student = $this->enrolledStudent();
        $batchId = $student->batchEnrollment->batch_id;
        $monday = Carbon::now()->startOfWeek(Carbon::MONDAY);

        $this->entry($student->id, $batchId, $monday->toDateString(), 'submitted', 'Set up the dev environment.');
        $this->entry($student->id, $batchId, $monday->copy()->addDays(2)->toDateString(), 'submitted', 'Fixed the login bug.');

        $result = (new WeeklyBundlingService)->bundleWeek($monday);

        $this->assertSame(1, $result['compiled']);
        $this->assertSame(0, $result['skipped_submitted']);

        $log = $this->weeklyLogFor($student->id, $monday);

        $this->assertNotNull($log);
        $this->assertSame(
            "MONDAY\nSet up the dev environment.\n\nWEDNESDAY\nFixed the login bug.",
            $log->narrative
        );
        $this->assertStringNotContainsString(':', $log->narrative); // no time range ever appears
        $this->assertSame($monday->copy()->addDays(6)->toDateString(), $log->week_end->toDateString());
    }

    public function test_days_with_no_submitted_entry_are_silently_skipped(): void
    {
        $student = $this->enrolledStudent();
        $batchId = $student->batchEnrollment->batch_id;
        $monday = Carbon::now()->startOfWeek(Carbon::MONDAY);

        $this->entry($student->id, $batchId, $monday->toDateString(), 'submitted', 'Only Monday was submitted.');
        // Tuesday: a draft entry exists but was never submitted -> must not appear.
        $this->entry($student->id, $batchId, $monday->copy()->addDay()->toDateString(), 'draft', 'Should not appear.');
        // Wednesday-Friday: no entry at all.

        (new WeeklyBundlingService)->bundleWeek($monday);

        $log = $this->weeklyLogFor($student->id, $monday);

        $this->assertSame('MONDAY
Only Monday was submitted.', $log->narrative);
        $this->assertStringNotContainsString('TUESDAY', $log->narrative);
        $this->assertStringNotContainsString('Should not appear', $log->narrative);
    }

    /**
     * Interns are sometimes genuinely rostered on a Saturday. A weekend entry
     * they actually submitted must reach the narrative — this is the compliance
     * document supervisors review, and it used to drop the day silently while
     * still stamping week_end on the following Sunday.
     */
    public function test_submitted_weekend_entries_are_included(): void
    {
        $student = $this->enrolledStudent();
        $batchId = $student->batchEnrollment->batch_id;
        $monday = Carbon::now()->startOfWeek(Carbon::MONDAY);
        $saturday = $monday->copy()->addDays(5);
        $sunday = $monday->copy()->addDays(6);

        $this->entry($student->id, $batchId, $monday->toDateString(), 'submitted', 'Regular Monday work.');
        $this->entry($student->id, $batchId, $saturday->toDateString(), 'submitted', 'Covered the Saturday shift.');
        $this->entry($student->id, $batchId, $sunday->toDateString(), 'submitted', 'Inventory count.');

        (new WeeklyBundlingService)->bundleWeek($monday);

        $log = $this->weeklyLogFor($student->id, $monday);

        $this->assertSame(
            "MONDAY\nRegular Monday work.\n\nSATURDAY\nCovered the Saturday shift.\n\nSUNDAY\nInventory count.",
            $log->narrative
        );
    }

    /**
     * The flip side: presence of an entry is the ONLY signal. A Mon-Fri intern's
     * narrative must be byte-identical to what it was before weekend support,
     * with no empty SATURDAY/SUNDAY headers appearing.
     */
    public function test_a_weekend_with_no_entry_adds_no_day_header(): void
    {
        $student = $this->enrolledStudent();
        $batchId = $student->batchEnrollment->batch_id;
        $monday = Carbon::now()->startOfWeek(Carbon::MONDAY);

        $this->entry($student->id, $batchId, $monday->toDateString(), 'submitted', 'Monday only.');

        (new WeeklyBundlingService)->bundleWeek($monday);

        $log = $this->weeklyLogFor($student->id, $monday);

        $this->assertSame("MONDAY\nMonday only.", $log->narrative);
        $this->assertStringNotContainsString('SATURDAY', $log->narrative);
        $this->assertStringNotContainsString('SUNDAY', $log->narrative);
    }

    public function test_does_not_overwrite_an_already_submitted_weekly_log(): void
    {
        $student = $this->enrolledStudent();
        $batchId = $student->batchEnrollment->batch_id;
        $monday = Carbon::now()->startOfWeek(Carbon::MONDAY);

        $this->entry($student->id, $batchId, $monday->toDateString(), 'submitted', 'Fresh compiled content.');

        WeeklyLog::create([
            'batch_id' => $batchId,
            'student_id' => $student->id,
            'week_start' => $monday->toDateString(),
            'week_end' => $monday->copy()->addDays(6)->toDateString(),
            'narrative' => 'The student already submitted this for review.',
            'status' => 'pending',
            'submitted_at' => now(),
        ]);

        $result = (new WeeklyBundlingService)->bundleWeek($monday);

        $this->assertSame(0, $result['compiled']);
        $this->assertSame(1, $result['skipped_submitted']);

        $log = $this->weeklyLogFor($student->id, $monday);
        $this->assertSame('The student already submitted this for review.', $log->narrative);
    }

    public function test_pre_fills_an_unsubmitted_draft_log(): void
    {
        $student = $this->enrolledStudent();
        $batchId = $student->batchEnrollment->batch_id;
        $monday = Carbon::now()->startOfWeek(Carbon::MONDAY);

        $this->entry($student->id, $batchId, $monday->toDateString(), 'submitted', 'Compiled Monday text.');

        WeeklyLog::create([
            'batch_id' => $batchId,
            'student_id' => $student->id,
            'week_start' => $monday->toDateString(),
            'week_end' => $monday->copy()->addDays(6)->toDateString(),
            'narrative' => 'Draft text the student was typing.',
            'status' => 'pending',
            'submitted_at' => null,
        ]);

        $result = (new WeeklyBundlingService)->bundleWeek($monday);

        $this->assertSame(1, $result['compiled']);

        $log = $this->weeklyLogFor($student->id, $monday);
        $this->assertSame("MONDAY\nCompiled Monday text.", $log->narrative);
    }

    /**
     * A week is complete only once its SUNDAY has passed. On a Saturday the
     * current week is still running — a Saturday shift may not even have been
     * written yet — so the answer is the previous Monday, not this one. (The
     * old Friday pivot returned this week's Monday here, which is precisely how
     * the one-way bundle lock used to land on students mid-Saturday.)
     */
    public function test_most_recently_completed_week_start_on_a_saturday_is_the_previous_monday(): void
    {
        $saturday = Carbon::parse('2026-07-11'); // a Saturday; its own week is not over yet
        $expectedMonday = Carbon::parse('2026-06-29'); // the Monday before this week's Monday

        $result = WeeklyBundlingService::mostRecentlyCompletedWeekStart($saturday);

        $this->assertSame($expectedMonday->toDateString(), $result->toDateString());
    }

    public function test_most_recently_completed_week_start_on_a_monday_is_the_week_that_just_ended(): void
    {
        $monday = Carbon::parse('2026-07-06'); // a Monday — the scheduled run day
        $expectedMonday = Carbon::parse('2026-06-29'); // the Mon-Sun week that ended yesterday

        $result = WeeklyBundlingService::mostRecentlyCompletedWeekStart($monday);

        $this->assertSame($expectedMonday->toDateString(), $result->toDateString());
    }

    public function test_most_recently_completed_week_start_mid_week_is_last_weeks_monday(): void
    {
        $wednesday = Carbon::parse('2026-07-08'); // a Wednesday, this week is still running
        $expectedMonday = Carbon::parse('2026-06-29'); // the previous Monday

        $result = WeeklyBundlingService::mostRecentlyCompletedWeekStart($wednesday);

        $this->assertSame($expectedMonday->toDateString(), $result->toDateString());
    }
}
