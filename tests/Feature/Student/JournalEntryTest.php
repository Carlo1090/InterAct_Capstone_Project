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
    use RefreshDatabase;
    use EnrollsStudentInBatch;

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

    public function test_a_bundled_week_locks_its_daily_entries(): void
    {
        $student = $this->enrolledStudent();
        Sanctum::actingAs($student, ['*']);

        $entryDate = now()->toDateString();

        $this->postJson('/api/student/journal-entries', [
            'entry_date' => $entryDate,
            'status' => 'submitted',
            'content' => ['task_performed' => 'First submission.'],
        ])->assertOk();

        $enrollment = BatchStudent::where('student_id', $student->id)->firstOrFail();
        $monday = Carbon::parse($entryDate)->startOfWeek(Carbon::MONDAY);

        WeeklyLog::create([
            'batch_id' => $enrollment->batch_id,
            'student_id' => $student->id,
            'week_start' => $monday->toDateString(),
            'week_end' => $monday->copy()->addDays(4)->toDateString(),
            'status' => 'pending',
            'narrative' => 'Compiled narrative.',
        ]);

        $response = $this->postJson('/api/student/journal-entries', [
            'entry_date' => $entryDate,
            'status' => 'submitted',
            'content' => ['task_performed' => 'Trying to change it after bundling.'],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'This week has already been compiled into your Weekly Log and can no longer be edited.');
        $this->assertDatabaseHas('journal_entries', [
            'student_id' => $student->id,
            'content->task_performed' => 'First submission.',
        ]);

        $showResponse = $this->getJson("/api/student/journal-entries/{$entryDate}");
        $showResponse->assertOk();
        $showResponse->assertJsonPath('editable', false);
        $showResponse->assertJsonPath('locked_reason', 'bundled');
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
