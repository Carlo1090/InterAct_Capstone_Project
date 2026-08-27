<?php

namespace Tests\Feature\Supervisor;

use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\Company;
use App\Models\CompanySupervisor;
use App\Models\Department;
use App\Models\JournalEntry;
use App\Models\Program;
use App\Models\User;
use App\Models\WeeklyLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The per-intern journal notebook behind the "Journals" action on My Interns:
 * every week one student has handed in, in one list, as opposed to the review
 * queue's one-status-at-a-time slice across every intern.
 */
class SupervisorInternNotebookTest extends TestCase
{
    use RefreshDatabase;

    private function program(): Program
    {
        $department = Department::firstOrCreate(['code' => 'CAST'], ['name' => 'CAST Dept', 'is_active' => true]);

        return Program::firstOrCreate(
            ['department_id' => $department->id, 'code' => 'BSIT'],
            ['name' => 'BSIT Program', 'is_active' => true]
        );
    }

    private function batch(Program $program): Batch
    {
        return Batch::create([
            'program_id' => $program->id,
            'coordinator_id' => User::factory()->create(['role' => 'coordinator'])->id,
            'name' => 'Batch '.uniqid(),
            'start_date' => now()->subMonths(2),
            'end_date' => now()->addMonth(),
            'required_hours' => 486,
            'working_days_per_week' => 5,
            'daily_reminder_time' => '21:00:00',
            'academic_year' => '2026',
            'semester' => 'Internship',
            'is_active' => true,
        ]);
    }

    /** @return array{0: User, 1: Batch, 2: User} supervisor, batch, student */
    private function scenario(): array
    {
        $program = $this->program();
        $batch = $this->batch($program);
        $supervisor = User::factory()->create(['role' => 'supervisor']);
        $student = User::factory()->create(['role' => 'student', 'program_id' => $program->id]);

        $company = Company::create(['name' => 'Co '.uniqid(), 'address' => 'A', 'is_active' => true]);
        CompanySupervisor::create([
            'company_id' => $company->id,
            'user_id' => $supervisor->id,
            'position' => 'Supervisor',
        ]);
        BatchStudent::create([
            'batch_id' => $batch->id,
            'student_id' => $student->id,
            'company_id' => $company->id,
            'supervisor_id' => $supervisor->id,
            'status' => 'active',
        ]);

        return [$supervisor, $batch, $student];
    }

    private function log(User $student, Batch $batch, int $weeksAgo, string $status, bool $submitted): WeeklyLog
    {
        $start = now()->startOfWeek()->subWeeks($weeksAgo);

        return WeeklyLog::create([
            'student_id' => $student->id,
            'batch_id' => $batch->id,
            'week_start' => $start->toDateString(),
            'week_end' => $start->copy()->addDays(6)->toDateString(),
            'status' => $status,
            'narrative' => "Narrative for the week of {$start->toDateString()}.",
            'submitted_at' => $submitted ? now() : null,
        ]);
    }

    /**
     * Oldest first (a notebook reads front to back), never-submitted drafts
     * left out, and — the load-bearing part — "Week N" counted over ALL of the
     * student's logs so it matches the number the PDF prints. A gap in the
     * list is therefore honest: that week exists but has not been handed in.
     */
    public function test_the_notebook_lists_submitted_weeks_oldest_first_with_pdf_week_numbers(): void
    {
        [$supervisor, $batch, $student] = $this->scenario();

        $first = $this->log($student, $batch, 3, 'approved', submitted: true);
        $this->log($student, $batch, 2, 'pending', submitted: false); // draft — hidden
        $third = $this->log($student, $batch, 1, 'pending', submitted: true);

        JournalEntry::create([
            'student_id' => $student->id,
            'batch_id' => $batch->id,
            'entry_date' => now()->startOfWeek()->subWeeks(3)->addDay()->toDateString(),
            'content' => ['daily_accomplishment' => 'Did work.'],
            'status' => 'submitted',
        ]);

        Sanctum::actingAs($supervisor, ['*']);

        $response = $this->getJson("/api/supervisor/interns/{$student->id}/journals");

        $response->assertOk();
        $response->assertJsonPath('student.id', $student->id);
        $response->assertJsonPath('totals.total', 2);
        $response->assertJsonPath('totals.pending', 1);
        $response->assertJsonPath('totals.approved', 1);
        $response->assertJsonPath('totals.drafts_hidden', 1);

        $weeks = $response->json('weeks');
        $this->assertCount(2, $weeks);

        // Oldest first, and numbered 1 and 3 — the draft occupies 2.
        $this->assertSame($first->id, $weeks[0]['id']);
        $this->assertSame(1, $weeks[0]['week_number']);
        $this->assertSame(1, $weeks[0]['entries_count']);
        $this->assertFalse($weeks[0]['reviewable']);

        $this->assertSame($third->id, $weeks[1]['id']);
        $this->assertSame(3, $weeks[1]['week_number']);
        $this->assertSame(0, $weeks[1]['entries_count']);
        $this->assertTrue($weeks[1]['reviewable']);
    }

    public function test_a_notebook_for_someone_elses_intern_is_forbidden(): void
    {
        [, $batch, $student] = $this->scenario();
        $this->log($student, $batch, 1, 'pending', submitted: true);

        Sanctum::actingAs(User::factory()->create(['role' => 'supervisor']), ['*']);

        $this->getJson("/api/supervisor/interns/{$student->id}/journals")->assertStatus(403);
    }

    public function test_a_notebook_for_a_non_student_account_is_not_found(): void
    {
        [$supervisor] = $this->scenario();
        $coordinator = User::factory()->create(['role' => 'coordinator']);

        Sanctum::actingAs($supervisor, ['*']);

        $this->getJson("/api/supervisor/interns/{$coordinator->id}/journals")->assertStatus(404);
    }

    public function test_an_intern_with_nothing_submitted_returns_an_empty_notebook(): void
    {
        [$supervisor, $batch, $student] = $this->scenario();
        $this->log($student, $batch, 1, 'pending', submitted: false);

        Sanctum::actingAs($supervisor, ['*']);

        $response = $this->getJson("/api/supervisor/interns/{$student->id}/journals");

        $response->assertOk();
        $response->assertJsonPath('totals.total', 0);
        $response->assertJsonPath('totals.drafts_hidden', 1);
        $this->assertSame([], $response->json('weeks'));
    }
}
