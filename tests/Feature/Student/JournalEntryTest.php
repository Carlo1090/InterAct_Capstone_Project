<?php

namespace Tests\Feature\Student;

use App\Models\BatchStudent;
use App\Models\JournalEntry;
use App\Models\WeeklyLog;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Student\Concerns\EnrollsStudentInBatch;
use Tests\TestCase;

class JournalEntryTest extends TestCase
{
    use EnrollsStudentInBatch;
    use RefreshDatabase;

    public function test_student_can_download_a_daily_entry_pdf(): void
    {
        $student = $this->enrolledStudent();
        Sanctum::actingAs($student, ['*']);

        $date = now()->toDateString();

        $this->postJson('/api/student/journal-entries', [
            'entry_date' => $date,
            'status' => 'submitted',
            'content' => ['task_performed' => 'Worked on the reporting module.'],
        ])->assertOk();

        $response = $this->get("/api/student/journal-entries/{$date}/pdf");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_student_can_submit_todays_entry(): void
    {
        $student = $this->enrolledStudent();
        Sanctum::actingAs($student, ['*']);

        $response = $this->postJson('/api/student/journal-entries', [
            'entry_date' => now()->toDateString(),
            'status' => 'submitted',
            'content' => ['task_performed' => 'Worked on the UI component library.'],
        ]);

        $response->assertOk()->assertJsonPath('status', 'submitted');
        $this->assertDatabaseHas('journal_entries', [
            'student_id' => $student->id,
            'status' => 'submitted',
        ]);
    }

    public function test_student_can_backfill_a_past_working_day(): void
    {
        $student = $this->enrolledStudent();
        Sanctum::actingAs($student, ['*']);

        $pastDate = now()->subDays(3)->toDateString();

        $response = $this->postJson('/api/student/journal-entries', [
            'entry_date' => $pastDate,
            'status' => 'draft',
            'content' => ['task_performed' => 'Backfilled entry.'],
        ]);

        $response->assertOk();
        $this->assertTrue(
            JournalEntry::where('student_id', $student->id)->whereDate('entry_date', $pastDate)->exists()
        );
    }

    public function test_student_cannot_submit_a_future_date(): void
    {
        $student = $this->enrolledStudent();
        Sanctum::actingAs($student, ['*']);

        $response = $this->postJson('/api/student/journal-entries', [
            'entry_date' => now()->addDays(2)->toDateString(),
            'status' => 'draft',
            'content' => ['task_performed' => 'Should not be allowed.'],
        ]);

        $response->assertStatus(422);
    }

    public function test_student_cannot_see_another_students_entry_content(): void
    {
        $studentA = $this->enrolledStudent();
        $studentB = $this->enrolledStudent();

        Sanctum::actingAs($studentB, ['*']);
        $this->postJson('/api/student/journal-entries', [
            'entry_date' => now()->toDateString(),
            'status' => 'submitted',
            'content' => ['task_performed' => "Student B's private entry."],
        ])->assertOk();

        Sanctum::actingAs($studentA, ['*']);
        $response = $this->getJson('/api/student/journal-entries/'.now()->toDateString());

        $response->assertOk();
        $this->assertSame('draft', $response->json('status'));
        $this->assertSame([], $response->json('content'));
    }

    public function test_submit_with_only_task_performed_succeeds(): void
    {
        $student = $this->enrolledStudent();
        Sanctum::actingAs($student, ['*']);

        $response = $this->postJson('/api/student/journal-entries', [
            'entry_date' => now()->toDateString(),
            'status' => 'submitted',
            'content' => ['task_performed' => 'Only the required field filled in.'],
        ]);

        $response->assertOk()->assertJsonPath('status', 'submitted');
    }

    public function test_submit_missing_task_performed_fails(): void
    {
        $student = $this->enrolledStudent();
        Sanctum::actingAs($student, ['*']);

        $response = $this->postJson('/api/student/journal-entries', [
            'entry_date' => now()->toDateString(),
            'status' => 'submitted',
            'content' => ['skills_applied' => 'Used Laravel and Vue.'],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['content.task_performed']);
    }

    public function test_submit_over_char_limit_is_rejected(): void
    {
        $student = $this->enrolledStudent();
        Sanctum::actingAs($student, ['*']);

        // Default template char_limit is 1500; exceed it.
        $overLimitText = str_repeat('a', 1501);

        $response = $this->postJson('/api/student/journal-entries', [
            'entry_date' => now()->toDateString(),
            'status' => 'submitted',
            'content' => ['task_performed' => $overLimitText],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['content']);
    }

    public function test_submit_at_char_limit_is_accepted(): void
    {
        $student = $this->enrolledStudent();
        Sanctum::actingAs($student, ['*']);

        // Exactly at the 1500-character limit should be accepted.
        $atLimitText = str_repeat('a', 1500);

        $response = $this->postJson('/api/student/journal-entries', [
            'entry_date' => now()->toDateString(),
            'status' => 'submitted',
            'content' => ['task_performed' => $atLimitText],
        ]);

        $response->assertOk();
    }

    public function test_optional_sipp_field_saves_and_is_retrievable(): void
    {
        $student = $this->enrolledStudent();
        Sanctum::actingAs($student, ['*']);

        $entryDate = now()->toDateString();

        $this->postJson('/api/student/journal-entries', [
            'entry_date' => $entryDate,
            'status' => 'submitted',
            'content' => [
                'task_performed' => 'Fixed a production bug.',
                'issues_concerns' => 'Deployment pipeline was flaky.',
            ],
        ])->assertOk();

        $response = $this->getJson("/api/student/journal-entries/{$entryDate}");

        $response->assertOk();
        $this->assertSame('Deployment pipeline was flaky.', $response->json('content.issues_concerns'));
    }

    public function test_sipp_field_over_300_characters_is_rejected(): void
    {
        $student = $this->enrolledStudent();
        Sanctum::actingAs($student, ['*']);

        $response = $this->postJson('/api/student/journal-entries', [
            'entry_date' => now()->toDateString(),
            'status' => 'submitted',
            'content' => [
                'task_performed' => 'Fixed a production bug.',
                'issues_concerns' => str_repeat('a', 301),
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['content.issues_concerns']);
    }

    public function test_sipp_field_at_300_characters_is_accepted(): void
    {
        $student = $this->enrolledStudent();
        Sanctum::actingAs($student, ['*']);

        $response = $this->postJson('/api/student/journal-entries', [
            'entry_date' => now()->toDateString(),
            'status' => 'submitted',
            'content' => [
                'task_performed' => 'Fixed a production bug.',
                'issues_concerns' => str_repeat('a', 300),
            ],
        ]);

        $response->assertOk();
    }

    public function test_a_submitted_entry_stays_editable_before_its_week_is_bundled(): void
    {
        $student = $this->enrolledStudent();
        Sanctum::actingAs($student, ['*']);

        $entryDate = now()->toDateString();

        $this->postJson('/api/student/journal-entries', [
            'entry_date' => $entryDate,
            'status' => 'submitted',
            'content' => ['task_performed' => 'First submission.'],
        ])->assertOk();

        $response = $this->postJson('/api/student/journal-entries', [
            'entry_date' => $entryDate,
            'status' => 'submitted',
            'content' => ['task_performed' => 'Revised after submission, before bundling.'],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('journal_entries', [
            'student_id' => $student->id,
            'content->task_performed' => 'Revised after submission, before bundling.',
        ]);
    }

    /**
     * The bug this pins: bundling used to be a one-way edit lock the moment a
     * WeeklyLog row existed — and one is stamped for EVERY active student every
     * Monday. So a student who filed Friday's entry on Monday morning, or who
     * came back to catch up on a fortnight, found the week frozen and had no
     * way to write it. Compilation is reversible now (the student can recompile
     * the week themselves), so it no longer freezes anything.
     */
    public function test_a_compiled_but_unsubmitted_week_leaves_its_daily_entries_writable(): void
    {
        $student = $this->enrolledStudent();
        Sanctum::actingAs($student, ['*']);

        $entryDate = now()->toDateString();

        $this->postJson('/api/student/journal-entries', [
            'entry_date' => $entryDate,
            'status' => 'submitted',
            'content' => ['task_performed' => 'First submission.'],
        ])->assertOk();

        $this->compiledWeekFor($student->id, $entryDate);

        $this->postJson('/api/student/journal-entries', [
            'entry_date' => $entryDate,
            'status' => 'submitted',
            'content' => ['task_performed' => 'Catching up after the week was compiled.'],
        ])->assertOk();

        $this->assertDatabaseHas('journal_entries', [
            'student_id' => $student->id,
            'content->task_performed' => 'Catching up after the week was compiled.',
        ]);

        $showResponse = $this->getJson("/api/student/journal-entries/{$entryDate}");
        $showResponse->assertOk();
        $showResponse->assertJsonPath('editable', true);
        $showResponse->assertJsonPath('locked_reason', null);
    }

    /**
     * Where the line actually sits now: once the week is with the supervisor,
     * its daily entries are frozen — the narrative they are reading must not
     * shift underneath them.
     */
    public function test_a_week_submitted_to_the_supervisor_locks_its_daily_entries(): void
    {
        $student = $this->enrolledStudent();
        Sanctum::actingAs($student, ['*']);

        $entryDate = now()->toDateString();

        $this->postJson('/api/student/journal-entries', [
            'entry_date' => $entryDate,
            'status' => 'submitted',
            'content' => ['task_performed' => 'First submission.'],
        ])->assertOk();

        $this->compiledWeekFor($student->id, $entryDate, ['submitted_at' => now(), 'status' => 'pending']);

        $response = $this->postJson('/api/student/journal-entries', [
            'entry_date' => $entryDate,
            'status' => 'submitted',
            'content' => ['task_performed' => 'Trying to change it after submitting the week.'],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('journal_entries', [
            'student_id' => $student->id,
            'content->task_performed' => 'First submission.',
        ]);

        $showResponse = $this->getJson("/api/student/journal-entries/{$entryDate}");
        $showResponse->assertOk();
        $showResponse->assertJsonPath('editable', false);
        $showResponse->assertJsonPath('locked_reason', 'week_submitted');
    }

    /**
     * A supervisor returning the week hands it back, so the daily entries
     * behind it reopen too — otherwise "fix it and resubmit" would only ever
     * mean rewriting the narrative by hand.
     */
    public function test_a_returned_week_reopens_its_daily_entries(): void
    {
        $student = $this->enrolledStudent();
        Sanctum::actingAs($student, ['*']);

        $entryDate = now()->toDateString();

        $this->postJson('/api/student/journal-entries', [
            'entry_date' => $entryDate,
            'status' => 'submitted',
            'content' => ['task_performed' => 'First submission.'],
        ])->assertOk();

        $this->compiledWeekFor($student->id, $entryDate, [
            'submitted_at' => now(),
            'status' => 'returned',
            'supervisor_comment' => 'Please add more detail to Wednesday.',
        ]);

        $this->postJson('/api/student/journal-entries', [
            'entry_date' => $entryDate,
            'status' => 'submitted',
            'content' => ['task_performed' => 'Revised after the supervisor returned the week.'],
        ])->assertOk();

        $this->getJson("/api/student/journal-entries/{$entryDate}")
            ->assertOk()
            ->assertJsonPath('locked_reason', null);
    }

    /**
     * A WeeklyLog covering the Mon-Sun week that contains $date.
     */
    private function compiledWeekFor(int $studentId, string $date, array $overrides = []): WeeklyLog
    {
        $enrollment = BatchStudent::where('student_id', $studentId)->firstOrFail();
        $monday = Carbon::parse($date)->startOfWeek(Carbon::MONDAY);

        return WeeklyLog::create([
            'batch_id' => $enrollment->batch_id,
            'student_id' => $studentId,
            'week_start' => $monday->toDateString(),
            'week_end' => $monday->copy()->addDays(6)->toDateString(),
            'status' => 'pending',
            'narrative' => 'Compiled narrative.',
            ...$overrides,
        ]);
    }

    /**
     * The bug this fixes: bundling used to run Saturday 00:00 for the week that
     * was still in progress, and stamping a WeeklyLog is a one-way edit lock on
     * every daily entry in that week. So an intern rostered on a Saturday found
     * the day locked before their shift had even started — they could never
     * write it. Bundling now runs Monday, for the Mon-Sun week that has actually
     * ended, leaving the current week (Saturday included) writable.
     */
    public function test_a_saturday_entry_stays_writable_after_bundling_runs(): void
    {
        $saturday = Carbon::parse('2026-08-01'); // a Saturday
        $this->travelTo($saturday);

        $student = $this->enrolledStudent([
            'start_date' => $saturday->copy()->subMonth(),
            'end_date' => $saturday->copy()->addMonth(),
            'working_days_per_week' => 5, // a Mon-Fri batch working an extra Saturday
        ]);
        Sanctum::actingAs($student, ['*']);

        // Exactly what the schedule does: no --week-start, so it bundles whatever
        // mostRecentlyCompletedWeekStart() resolves to.
        $this->artisan('journal:run-weekly-bundling')->assertSuccessful();

        // The bundle must have landed on the PREVIOUS week, not this one.
        $thisMonday = $saturday->copy()->startOfWeek(Carbon::MONDAY);
        $this->assertDatabaseMissing('weekly_logs', [
            'student_id' => $student->id,
            'week_start' => $thisMonday->toDateString(),
        ]);

        $showResponse = $this->getJson('/api/student/journal-entries/'.$saturday->toDateString());
        $showResponse->assertOk();
        $showResponse->assertJsonPath('editable', true);
        $showResponse->assertJsonPath('locked_reason', null);

        $this->postJson('/api/student/journal-entries', [
            'entry_date' => $saturday->toDateString(),
            'status' => 'submitted',
            'content' => ['task_performed' => 'Covered the Saturday shift.'],
        ])->assertOk();

        $this->assertDatabaseHas('journal_entries', [
            'student_id' => $student->id,
            'content->task_performed' => 'Covered the Saturday shift.',
        ]);
    }

    public function test_draft_can_be_saved_and_overwritten_freely_before_submission(): void
    {
        $student = $this->enrolledStudent();
        Sanctum::actingAs($student, ['*']);

        $entryDate = now()->toDateString();

        $this->postJson('/api/student/journal-entries', [
            'entry_date' => $entryDate,
            'status' => 'draft',
            'content' => ['task_performed' => 'First draft.'],
        ])->assertOk();

        $response = $this->postJson('/api/student/journal-entries', [
            'entry_date' => $entryDate,
            'status' => 'draft',
            'content' => ['task_performed' => 'Revised draft.'],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('journal_entries', [
            'student_id' => $student->id,
            'content->task_performed' => 'Revised draft.',
        ]);
    }

    public function test_a_different_date_is_unaffected_by_another_dates_submission_lock(): void
    {
        $student = $this->enrolledStudent();
        Sanctum::actingAs($student, ['*']);

        $submittedDate = now()->toDateString();
        $otherDate = now()->subDay()->toDateString();

        $this->postJson('/api/student/journal-entries', [
            'entry_date' => $submittedDate,
            'status' => 'submitted',
            'content' => ['task_performed' => 'Submitted today.'],
        ])->assertOk();

        $response = $this->postJson('/api/student/journal-entries', [
            'entry_date' => $otherDate,
            'status' => 'submitted',
            'content' => ['task_performed' => 'Different date entirely.'],
        ]);

        $response->assertOk()->assertJsonPath('status', 'submitted');
    }

    public function test_all_three_sipp_fields_save_together(): void
    {
        $student = $this->enrolledStudent();
        Sanctum::actingAs($student, ['*']);

        $entryDate = now()->toDateString();

        $this->postJson('/api/student/journal-entries', [
            'entry_date' => $entryDate,
            'status' => 'submitted',
            'content' => [
                'task_performed' => 'Fixed a production bug.',
                'issues_concerns' => 'Deployment pipeline was flaky.',
                'solutions' => 'Rolled back and patched the config.',
                'recommendations' => 'Add a staging smoke test.',
            ],
        ])->assertOk();

        $response = $this->getJson("/api/student/journal-entries/{$entryDate}");

        $response->assertOk();
        $this->assertSame('Deployment pipeline was flaky.', $response->json('content.issues_concerns'));
        $this->assertSame('Rolled back and patched the config.', $response->json('content.solutions'));
        $this->assertSame('Add a staging smoke test.', $response->json('content.recommendations'));
    }
}
