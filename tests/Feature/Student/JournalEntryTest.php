<?php

namespace Tests\Feature\Student;

use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\Company;
use App\Models\Department;
use App\Models\JournalEntry;
use App\Models\JournalTemplate;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class JournalEntryTest extends TestCase
{
    use RefreshDatabase;

    private function enrolledStudent(): User
    {
        $department = Department::firstOrCreate(
            ['code' => 'CAST'],
            ['name' => 'College of Arts, Sciences and Technology', 'is_active' => true]
        );
        $program = Program::firstOrCreate(
            ['department_id' => $department->id, 'code' => 'BSIT'],
            ['name' => 'BS Information Technology', 'is_active' => true]
        );
        $coordinator = User::factory()->create(['role' => 'coordinator']);
        $supervisor = User::factory()->create(['role' => 'supervisor']);
        $company = Company::create(['name' => 'TechPH Inc. '.uniqid(), 'address' => 'Cebu City', 'is_active' => true]);
        $student = User::factory()->create(['role' => 'student', 'program_id' => $program->id]);

        $template = JournalTemplate::create([
            'program_id' => $program->id,
            'name' => 'BSIT Daily Journal Template '.uniqid(),
            'sections' => [
                ['label' => 'Tasks Performed', 'prompt' => 'Describe the tasks.'],
            ],
            'is_active' => true,
        ]);

        $batch = Batch::create([
            'program_id' => $program->id,
            'coordinator_id' => $coordinator->id,
            'name' => 'Program 2025-A '.uniqid(),
            'start_date' => now()->subMonth(),
            'end_date' => now()->addMonth(),
            'required_hours' => 486,
            'working_days_per_week' => 5,
            'daily_reminder_time' => '21:00:00',
            'journal_template_id' => $template->id,
            'academic_year' => now()->format('Y'),
            'semester' => 'Internship',
            'is_active' => true,
        ]);

        BatchStudent::create([
            'batch_id' => $batch->id,
            'student_id' => $student->id,
            'company_id' => $company->id,
            'supervisor_id' => $supervisor->id,
            'status' => 'active',
        ]);

        return $student;
    }

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
