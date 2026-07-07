<?php

namespace Tests\Feature\Student;

use App\Models\JournalEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Student\Concerns\EnrollsStudentInBatch;
use Tests\TestCase;

class JournalEntryTest extends TestCase
{
    use RefreshDatabase;
    use EnrollsStudentInBatch;

    public function test_student_can_submit_todays_entry(): void
    {
        $student = $this->enrolledStudent();
        Sanctum::actingAs($student, ['*']);

        $response = $this->postJson('/api/student/journal-entries', [
            'entry_date' => now()->toDateString(),
            'status' => 'submitted',
            'content' => ['Tasks Performed' => 'Worked on the UI component library.'],
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
            'content' => ['Tasks Performed' => 'Backfilled entry.'],
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
            'content' => ['Tasks Performed' => 'Should not be allowed.'],
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
            'content' => ['Tasks Performed' => "Student B's private entry."],
        ])->assertOk();

        Sanctum::actingAs($studentA, ['*']);
        $response = $this->getJson('/api/student/journal-entries/'.now()->toDateString());

        $response->assertOk();
        $this->assertSame('draft', $response->json('status'));
        $this->assertSame([], $response->json('content'));
    }
}
