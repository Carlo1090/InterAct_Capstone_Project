<?php

namespace Tests\Feature\Student;

use App\Models\BatchStudent;
use App\Models\JournalEntry;
use App\Models\StudentInformationSheet;
use App\Models\User;
use App\Models\WeeklyLog;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Student\Concerns\EnrollsStudentInBatch;
use Tests\TestCase;

/**
 * The OJT journal window is REAL-TIME: batch start .. today while the
 * enrollment is active, frozen at completed_at once the coordinator marks
 * it completed. The info sheet's ojt_start_date/ojt_end_date are
 * informational estimates with no functional effect.
 */
class RealtimeJournalRangeTest extends TestCase
{
    use RefreshDatabase;
    use EnrollsStudentInBatch;

    private function enrollmentOf(User $student): BatchStudent
    {
        return BatchStudent::where('student_id', $student->id)->firstOrFail();
    }

    private function submitEntry(User $student, string $date, string $text = 'Worked on assigned tasks.'): JournalEntry
    {
        return JournalEntry::create([
            'student_id' => $student->id,
            'batch_id' => $this->enrollmentOf($student)->batch_id,
            'entry_date' => $date,
            'content' => ['task_performed' => $text],
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);
    }

    public function test_current_week_date_is_editable_and_a_future_date_is_not(): void
    {
        $student = $this->enrolledStudent();
        Sanctum::actingAs($student, ['*']);

        $this->getJson('/api/student/journal-entries/'.now()->toDateString())
            ->assertOk()
            ->assertJsonPath('editable', true);

        $this->getJson('/api/student/journal-entries/'.now()->addDays(3)->toDateString())
            ->assertOk()
            ->assertJsonPath('editable', false);
    }

    public function test_current_week_card_appears_once_the_student_has_written(): void
    {
        $student = $this->enrolledStudent();
        Sanctum::actingAs($student, ['*']);

        $this->submitEntry($student, now()->toDateString());

        $response = $this->getJson('/api/student/weekly-logs')->assertOk();

        $weekStarts = collect($response->json('weeks'))->pluck('week_start');
        $this->assertTrue(
            $weekStarts->contains(now()->startOfWeek(Carbon::MONDAY)->toDateString()),
            'The rolling current week card should appear.'
        );
    }

    public function test_weekly_list_is_empty_before_any_work_exists(): void
    {
        $student = $this->enrolledStudent();
        Sanctum::actingAs($student, ['*']);

        $this->getJson('/api/student/weekly-logs')
            ->assertOk()
            ->assertJsonPath('weeks', []);
    }

    public function test_past_estimate_end_date_on_the_sheet_does_not_restrict_editability(): void
    {
        $student = $this->enrolledStudent();
        $enrollment = $this->enrollmentOf($student);

        StudentInformationSheet::create([
            'student_id' => $student->id,
            'batch_id' => $enrollment->batch_id,
            'personal_info' => [],
            'academic_info' => [],
            'emergency_contact' => [],
            'ojt_info' => [
                'ojt_start_date' => now()->subMonths(3)->toDateString(),
                'ojt_end_date' => now()->subMonth()->toDateString(),
            ],
            'submission_status' => 'approved',
        ]);

        Sanctum::actingAs($student, ['*']);

        $this->getJson('/api/student/journal-entries/'.now()->toDateString())
            ->assertOk()
            ->assertJsonPath('editable', true);

        $this->postJson('/api/student/journal-entries', [
            'entry_date' => now()->toDateString(),
            'status' => 'submitted',
            'content' => ['task_performed' => 'The stale estimate must not block this.'],
        ])->assertOk();
    }

    public function test_completion_freezes_the_weekly_list_at_the_completion_week(): void
    {
        $student = $this->enrolledStudent();
        Sanctum::actingAs($student, ['*']);

        $twoWeeksAgo = now()->subWeeks(2)->startOfWeek(Carbon::MONDAY);
        $this->submitEntry($student, $twoWeeksAgo->toDateString());

        $currentWeek = now()->startOfWeek(Carbon::MONDAY)->toDateString();

        $before = collect($this->getJson('/api/student/weekly-logs')->assertOk()->json('weeks'))->pluck('week_start');
        $this->assertTrue($before->contains($currentWeek));

        // Complete with a backdated completion: the hook keeps an explicit
        // completed_at, so the window freezes two weeks back.
        $enrollment = $this->enrollmentOf($student);
        $enrollment->status = 'completed';
        $enrollment->completed_at = $twoWeeksAgo->copy()->addDays(4);
        $enrollment->save();

        $after = collect($this->getJson('/api/student/weekly-logs')->assertOk()->json('weeks'))->pluck('week_start');
        $this->assertFalse($after->contains($currentWeek), 'The current week card must disappear after completion.');
        $this->assertSame([$twoWeeksAgo->toDateString()], $after->all());
    }

    public function test_completed_student_cannot_write_but_keeps_full_read_access(): void
    {
        $student = $this->enrolledStudent();
        Sanctum::actingAs($student, ['*']);

        $entryDate = now()->subDay()->toDateString();
        $this->submitEntry($student, $entryDate, 'Final week of work.');

        $weekStart = Carbon::parse($entryDate)->startOfWeek(Carbon::MONDAY)->toDateString();
        $this->postJson('/api/student/weekly-logs', [
            'week_start' => $weekStart,
            'narrative' => 'Wrapping up my OJT.',
        ])->assertOk();

        $this->enrollmentOf($student)->update(['status' => 'completed']);

        // Writes lock: dates after completed_at (and any date) 422 on store.
        $this->postJson('/api/student/journal-entries', [
            'entry_date' => now()->toDateString(),
            'status' => 'draft',
            'content' => ['task_performed' => 'Should be locked.'],
        ])->assertStatus(422);

        $this->postJson('/api/student/weekly-logs', [
            'week_start' => $weekStart,
            'narrative' => 'Should be locked too.',
        ])->assertStatus(422);

        // Reads stay open: content/sections come back, just not editable.
        $show = $this->getJson("/api/student/journal-entries/{$entryDate}")->assertOk();
        $this->assertFalse($show->json('editable'));
        $this->assertSame('Final week of work.', $show->json('content.task_performed'));
        $this->assertNotEmpty($show->json('sections'));

        $this->getJson('/api/student/weekly-logs')->assertOk();
        $this->assertSame('Wrapping up my OJT.', $this->getJson("/api/student/weekly-logs/{$weekStart}")->assertOk()->json('narrative'));

        $this->get("/api/student/journal-entries/{$entryDate}/pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $this->get("/api/student/weekly-logs/{$weekStart}/pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_calendar_days_after_completion_are_no_entry_but_stay_future_while_active(): void
    {
        $student = $this->enrolledStudent();
        Sanctum::actingAs($student, ['*']);

        $month = now()->format('Y-m');
        $tomorrow = now()->addDay()->toDateString();

        // Rolling window: a future day is 'future' while active (when it
        // falls inside this month's calendar page).
        if (Carbon::parse($tomorrow)->format('Y-m') === $month) {
            $days = collect($this->getJson("/api/student/journal-calendar?month={$month}")->assertOk()->json('days'));
            $this->assertSame('future', $days->firstWhere('date', $tomorrow)['status']);
        }

        // Frozen window: backdate completion to 3 days ago; later days are
        // outside the range for good.
        $enrollment = $this->enrollmentOf($student);
        $enrollment->status = 'completed';
        $enrollment->completed_at = now()->subDays(3);
        $enrollment->save();

        $days = collect($this->getJson("/api/student/journal-calendar?month={$month}")->assertOk()->json('days'));

        $dayAfterCompletion = now()->subDays(2)->toDateString();
        if (Carbon::parse($dayAfterCompletion)->format('Y-m') === $month) {
            $this->assertSame('no_entry', $days->firstWhere('date', $dayAfterCompletion)['status']);
        }
        if (Carbon::parse($tomorrow)->format('Y-m') === $month) {
            $this->assertSame('no_entry', $days->firstWhere('date', $tomorrow)['status']);
        }
    }

    public function test_weekly_bundler_skips_completed_students_and_their_existing_logs_persist(): void
    {
        $student = $this->enrolledStudent();

        $lastMonday = now()->subWeek()->startOfWeek(Carbon::MONDAY);
        $this->submitEntry($student, $lastMonday->toDateString());

        $existing = WeeklyLog::create([
            'student_id' => $student->id,
            'batch_id' => $this->enrollmentOf($student)->batch_id,
            'week_start' => $lastMonday->copy()->subWeek()->toDateString(),
            'week_end' => $lastMonday->copy()->subWeek()->addDays(6)->toDateString(),
            'narrative' => 'An earlier week, already compiled.',
        ]);

        $this->enrollmentOf($student)->update(['status' => 'completed']);

        $result = app(\App\Services\WeeklyBundlingService::class)->bundleWeek($lastMonday);

        $this->assertSame(0, $result['compiled']);
        $this->assertFalse(
            WeeklyLog::where('student_id', $student->id)->whereDate('week_start', $lastMonday->toDateString())->exists(),
            'The bundler must not create a log for a completed student.'
        );
        $this->assertDatabaseHas('weekly_logs', [
            'id' => $existing->id,
            'narrative' => 'An earlier week, already compiled.',
        ]);
    }
}
