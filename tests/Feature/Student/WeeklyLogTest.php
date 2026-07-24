<?php

namespace Tests\Feature\Student;

use App\Models\JournalEntry;
use App\Models\WeeklyLog;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Student\Concerns\EnrollsStudentInBatch;
use Tests\TestCase;

class WeeklyLogTest extends TestCase
{
    use RefreshDatabase;
    use EnrollsStudentInBatch;

    public function test_student_can_save_a_weekly_narrative(): void
    {
        $student = $this->enrolledStudent();
        Sanctum::actingAs($student, ['*']);

        $weekStart = Carbon::now()->startOfWeek(Carbon::MONDAY);

        JournalEntry::create([
            'student_id' => $student->id,
            'batch_id' => $student->batchEnrollment->batch_id,
            'entry_date' => $weekStart->toDateString(),
            'content' => ['task_performed' => 'Kickoff meeting and environment setup.'],
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        $response = $this->postJson('/api/student/weekly-logs', [
            'week_start' => $weekStart->toDateString(),
            'narrative' => 'This week I focused on onboarding and initial setup.',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('weekly_logs', [
            'student_id' => $student->id,
            'narrative' => 'This week I focused on onboarding and initial setup.',
        ]);

        $reference = $this->getJson('/api/student/weekly-logs/'.$weekStart->toDateString());

        $reference->assertOk();
        $this->assertSame('This week I focused on onboarding and initial setup.', $reference->json('narrative'));
        $this->assertCount(1, $reference->json('daily_entries'));
        $this->assertSame($weekStart->toDateString(), Carbon::parse($reference->json('daily_entries.0.entry_date'))->toDateString());
    }

    public function test_student_can_download_a_weekly_log_pdf(): void
    {
        $student = $this->enrolledStudent();
        Sanctum::actingAs($student, ['*']);

        $weekStart = Carbon::now()->startOfWeek(Carbon::MONDAY);

        $this->postJson('/api/student/weekly-logs', [
            'week_start' => $weekStart->toDateString(),
            'narrative' => 'This week I focused on onboarding and initial setup.',
        ])->assertOk();

        $response = $this->get("/api/student/weekly-logs/{$weekStart->toDateString()}/pdf");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_saving_the_same_week_twice_updates_in_place_instead_of_duplicating(): void
    {
        $student = $this->enrolledStudent();
        Sanctum::actingAs($student, ['*']);

        $weekStart = Carbon::now()->startOfWeek(Carbon::MONDAY);

        $this->postJson('/api/student/weekly-logs', [
            'week_start' => $weekStart->toDateString(),
            'narrative' => 'First draft.',
        ])->assertOk();

        $this->postJson('/api/student/weekly-logs', [
            'week_start' => $weekStart->toDateString(),
            'narrative' => 'Revised draft.',
        ])->assertOk();

        $this->assertSame(1, \App\Models\WeeklyLog::where('student_id', $student->id)->count());
        $this->assertDatabaseHas('weekly_logs', ['student_id' => $student->id, 'narrative' => 'Revised draft.']);
    }

    public function test_weekly_sipp_notes_are_aggregated_from_daily_entries_and_kept_separate_from_narrative(): void
    {
        $student = $this->enrolledStudent();
        Sanctum::actingAs($student, ['*']);

        $weekStart = Carbon::now()->startOfWeek(Carbon::MONDAY);

        JournalEntry::create([
            'student_id' => $student->id,
            'batch_id' => $student->batchEnrollment->batch_id,
            'entry_date' => $weekStart->toDateString(),
            'content' => [
                'task_performed' => 'Kickoff meeting and environment setup.',
                'issues_concerns' => 'VPN access was delayed.',
            ],
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        JournalEntry::create([
            'student_id' => $student->id,
            'batch_id' => $student->batchEnrollment->batch_id,
            'entry_date' => $weekStart->copy()->addDay()->toDateString(),
            'content' => [
                'task_performed' => 'Paired on the onboarding checklist.',
                'solutions' => 'Coordinated with IT to restore VPN access.',
            ],
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        $this->postJson('/api/student/weekly-logs', [
            'week_start' => $weekStart->toDateString(),
            'narrative' => 'Narrative only, no SIPP text here.',
        ])->assertOk();

        $response = $this->getJson('/api/student/weekly-logs/'.$weekStart->toDateString());

        $response->assertOk();
        $this->assertSame('Narrative only, no SIPP text here.', $response->json('narrative'));

        $sippNotes = $response->json('sipp_notes');
        $this->assertCount(2, $sippNotes);

        $firstDay = collect($sippNotes)->firstWhere('entry_date', $weekStart->toDateString());
        $this->assertSame('VPN access was delayed.', collect($firstDay['fields'])->firstWhere('key', 'issues_concerns')['text']);

        $secondDay = collect($sippNotes)->firstWhere('entry_date', $weekStart->copy()->addDay()->toDateString());
        $this->assertSame('Coordinated with IT to restore VPN access.', collect($secondDay['fields'])->firstWhere('key', 'solutions')['text']);
    }

    public function test_submit_with_a_saved_draft_succeeds(): void
    {
        $student = $this->enrolledStudent();
        Sanctum::actingAs($student, ['*']);

        $weekStart = Carbon::now()->startOfWeek(Carbon::MONDAY);

        $this->postJson('/api/student/weekly-logs', [
            'week_start' => $weekStart->toDateString(),
            'narrative' => 'This week I focused on onboarding and initial setup.',
        ])->assertOk();

        $response = $this->postJson("/api/student/weekly-logs/{$weekStart->toDateString()}/submit");

        $response->assertOk();
        $response->assertJsonPath('status', 'pending');
        $this->assertNotNull($response->json('submitted_at'));

        $log = WeeklyLog::where('student_id', $student->id)->first();
        $this->assertSame('pending', $log->status);
        $this->assertNotNull($log->submitted_at);
    }

    public function test_submit_with_no_draft_fails(): void
    {
        $student = $this->enrolledStudent();
        Sanctum::actingAs($student, ['*']);

        $weekStart = Carbon::now()->startOfWeek(Carbon::MONDAY);

        $response = $this->postJson("/api/student/weekly-logs/{$weekStart->toDateString()}/submit");

        $response->assertStatus(422);
        $this->assertSame(0, WeeklyLog::where('student_id', $student->id)->count());
    }

    public function test_double_submit_fails(): void
    {
        $student = $this->enrolledStudent();
        Sanctum::actingAs($student, ['*']);

        $weekStart = Carbon::now()->startOfWeek(Carbon::MONDAY);

        $this->postJson('/api/student/weekly-logs', [
            'week_start' => $weekStart->toDateString(),
            'narrative' => 'First submission draft.',
        ])->assertOk();

        $this->postJson("/api/student/weekly-logs/{$weekStart->toDateString()}/submit")->assertOk();

        $response = $this->postJson("/api/student/weekly-logs/{$weekStart->toDateString()}/submit");

        $response->assertStatus(422);
        $this->assertSame(1, WeeklyLog::where('student_id', $student->id)->where('status', 'pending')->count());
    }

    public function test_editing_after_submit_is_rejected(): void
    {
        $student = $this->enrolledStudent();
        Sanctum::actingAs($student, ['*']);

        $weekStart = Carbon::now()->startOfWeek(Carbon::MONDAY);

        $this->postJson('/api/student/weekly-logs', [
            'week_start' => $weekStart->toDateString(),
            'narrative' => 'Original draft.',
        ])->assertOk();

        $this->postJson("/api/student/weekly-logs/{$weekStart->toDateString()}/submit")->assertOk();

        $response = $this->postJson('/api/student/weekly-logs', [
            'week_start' => $weekStart->toDateString(),
            'narrative' => 'Trying to edit after submit.',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('weekly_logs', ['student_id' => $student->id, 'narrative' => 'Original draft.']);
    }

    public function test_editing_an_approved_log_is_rejected(): void
    {
        $student = $this->enrolledStudent();
        Sanctum::actingAs($student, ['*']);

        $weekStart = Carbon::now()->startOfWeek(Carbon::MONDAY);

        $this->postJson('/api/student/weekly-logs', [
            'week_start' => $weekStart->toDateString(),
            'narrative' => 'Approved draft.',
        ])->assertOk();

        $log = WeeklyLog::where('student_id', $student->id)->first();
        $log->update(['submitted_at' => now(), 'status' => 'approved']);

        $response = $this->postJson('/api/student/weekly-logs', [
            'week_start' => $weekStart->toDateString(),
            'narrative' => 'Trying to edit an approved log.',
        ]);

        $response->assertStatus(422);
    }

    public function test_returned_log_can_be_edited_and_resubmitted(): void
    {
        $student = $this->enrolledStudent();
        Sanctum::actingAs($student, ['*']);

        $weekStart = Carbon::now()->startOfWeek(Carbon::MONDAY);

        $this->postJson('/api/student/weekly-logs', [
            'week_start' => $weekStart->toDateString(),
            'narrative' => 'First attempt.',
        ])->assertOk();

        $this->postJson("/api/student/weekly-logs/{$weekStart->toDateString()}/submit")->assertOk();

        $log = WeeklyLog::where('student_id', $student->id)->first();
        $firstSubmittedAt = $log->submitted_at;
        $log->update([
            'status' => 'returned',
            'supervisor_comment' => 'Please add more detail.',
        ]);

        // Editable again while returned.
        $this->postJson('/api/student/weekly-logs', [
            'week_start' => $weekStart->toDateString(),
            'narrative' => 'Revised after feedback.',
        ])->assertOk();

        $this->travel(1)->minute();

        $response = $this->postJson("/api/student/weekly-logs/{$weekStart->toDateString()}/submit");

        $response->assertOk();
        $response->assertJsonPath('status', 'pending');

        $log->refresh();
        $this->assertSame('pending', $log->status);
        $this->assertSame('Revised after feedback.', $log->narrative);
        $this->assertNotNull($log->submitted_at);
        $this->assertTrue($log->submitted_at->greaterThan($firstSubmittedAt));
    }

    /**
     * Regression: viewing a week that has daily journal entries but no
     * WeeklyLog row yet (the normal state before the first "Save Narrative")
     * used to 500 — `$log->submitted_at?->toIso8601String() ?? null` still
     * reads the `submitted_at` property directly off a null `$log` before
     * the nullsafe operator ever applies, and PHP's `??` only suppresses
     * that warning for a bare property/array access, not a chained
     * method-call expression. Caught while manually verifying the new
     * weekly-narrative character counter in a browser.
     */
    public function test_viewing_a_week_with_no_weekly_log_row_yet_does_not_error(): void
    {
        $student = $this->enrolledStudent();
        Sanctum::actingAs($student, ['*']);

        $weekStart = Carbon::now()->startOfWeek(Carbon::MONDAY);

        JournalEntry::create([
            'student_id' => $student->id,
            'batch_id' => $student->batchEnrollment->batch_id,
            'entry_date' => $weekStart->toDateString(),
            'content' => ['task_performed' => 'Day one, no weekly narrative saved yet.'],
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        $response = $this->getJson('/api/student/weekly-logs/'.$weekStart->toDateString());

        $response->assertOk();
        $response->assertJsonPath('status', null);
        $response->assertJsonPath('submitted_at', null);
        $response->assertJsonPath('narrative', '');
    }

    public function test_narrative_over_the_fixed_char_limit_is_rejected(): void
    {
        $student = $this->enrolledStudent();
        Sanctum::actingAs($student, ['*']);

        $weekStart = Carbon::now()->startOfWeek(Carbon::MONDAY);

        $response = $this->postJson('/api/student/weekly-logs', [
            'week_start' => $weekStart->toDateString(),
            'narrative' => str_repeat('a', 5001),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('narrative');
    }

    public function test_narrative_at_the_fixed_char_limit_is_accepted(): void
    {
        $student = $this->enrolledStudent();
        Sanctum::actingAs($student, ['*']);

        $weekStart = Carbon::now()->startOfWeek(Carbon::MONDAY);

        $response = $this->postJson('/api/student/weekly-logs', [
            'week_start' => $weekStart->toDateString(),
            'narrative' => str_repeat('a', 5000),
        ]);

        $response->assertOk();
    }
}
