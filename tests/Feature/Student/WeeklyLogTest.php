<?php

namespace Tests\Feature\Student;

use App\Models\JournalEntry;
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
}
