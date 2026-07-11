<?php

namespace Tests\Feature\Supervisor;

use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\Company;
use App\Models\Department;
use App\Models\Program;
use App\Models\User;
use App\Models\WeeklyLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SupervisorDashboardInternsTest extends TestCase
{
    use RefreshDatabase;

    private function program(string $code = 'BSIT', string $deptCode = 'CAST'): Program
    {
        $department = Department::firstOrCreate(
            ['code' => $deptCode],
            ['name' => $deptCode.' Department', 'is_active' => true]
        );

        return Program::firstOrCreate(
            ['department_id' => $department->id, 'code' => $code],
            ['name' => $code.' Program', 'is_active' => true]
        );
    }

    private function batch(Program $program): Batch
    {
        return Batch::create([
            'program_id' => $program->id,
            'coordinator_id' => User::factory()->create(['role' => 'coordinator'])->id,
            'name' => 'Batch '.uniqid(),
            'start_date' => now()->subMonth(),
            'end_date' => now()->addMonth(),
            'required_hours' => 486,
            'working_days_per_week' => 5,
            'daily_reminder_time' => '21:00:00',
            'academic_year' => '2026',
            'semester' => 'Internship',
            'is_active' => true,
        ]);
    }

    /** Enroll a fresh student under $supervisor in $batch, return the student. */
    private function intern(User $supervisor, Batch $batch, string $name = 'Intern'): User
    {
        $student = User::factory()->create(['role' => 'student', 'name' => $name, 'program_id' => $batch->program_id]);
        $company = Company::create(['name' => 'Co '.uniqid(), 'address' => 'A', 'is_active' => true]);
        BatchStudent::create([
            'batch_id' => $batch->id,
            'student_id' => $student->id,
            'company_id' => $company->id,
            'supervisor_id' => $supervisor->id,
            'status' => 'active',
        ]);

        return $student;
    }

    private function weeklyLog(User $student, Batch $batch, string $status, ?User $supervisor = null, bool $submitted = true): WeeklyLog
    {
        return WeeklyLog::create([
            'student_id' => $student->id,
            'batch_id' => $batch->id,
            'supervisor_id' => $supervisor?->id,
            'week_start' => now()->startOfWeek()->toDateString(),
            'week_end' => now()->startOfWeek()->addDays(6)->toDateString(),
            'status' => $status,
            'narrative' => 'Weekly narrative.',
            'submitted_at' => $submitted ? now() : null,
            'reviewed_at' => in_array($status, ['approved', 'returned'], true) ? now() : null,
        ]);
    }

    public function test_interns_lists_only_own_interns(): void
    {
        $program = $this->program();
        $batch = $this->batch($program);

        $supervisor = User::factory()->create(['role' => 'supervisor']);
        $mine = $this->intern($supervisor, $batch, 'My Intern');

        $otherSupervisor = $this->factoryOtherSupervisor($batch, 'Other Intern');

        Sanctum::actingAs($supervisor, ['*']);

        $response = $this->getJson('/api/supervisor/interns');

        $response->assertOk();
        $names = collect($response->json('interns'))->pluck('name');
        $this->assertTrue($names->contains('My Intern'));
        $this->assertFalse($names->contains('Other Intern'));
        $this->assertSame($mine->id, collect($response->json('interns'))->firstWhere('name', 'My Intern')['student_id']);
    }

    /** Create a different supervisor with their own intern in the same batch. */
    private function factoryOtherSupervisor(Batch $batch, string $internName): User
    {
        $other = User::factory()->create(['role' => 'supervisor']);
        $this->intern($other, $batch, $internName);

        return $other;
    }

    public function test_interns_include_weekly_log_counts(): void
    {
        $program = $this->program();
        $batch = $this->batch($program);
        $supervisor = User::factory()->create(['role' => 'supervisor']);
        $student = $this->intern($supervisor, $batch, 'Counter');

        $this->weeklyLog($student, $batch, 'pending', $supervisor);
        // A second pending in a different week.
        WeeklyLog::create([
            'student_id' => $student->id,
            'batch_id' => $batch->id,
            'week_start' => now()->startOfWeek()->subWeek()->toDateString(),
            'week_end' => now()->startOfWeek()->subWeek()->addDays(6)->toDateString(),
            'status' => 'approved',
            'narrative' => 'x',
            'submitted_at' => now(),
            'reviewed_at' => now(),
        ]);

        Sanctum::actingAs($supervisor, ['*']);

        $row = collect($this->getJson('/api/supervisor/interns')->json('interns'))->firstWhere('name', 'Counter');
        $this->assertSame(1, $row['pending_count']);
        $this->assertSame(1, $row['approved_count']);
    }

    public function test_dashboard_counts_are_scoped(): void
    {
        $program = $this->program();
        $batch = $this->batch($program);

        $supervisor = User::factory()->create(['role' => 'supervisor']);
        $a = $this->intern($supervisor, $batch, 'A');
        $b = $this->intern($supervisor, $batch, 'B');
        $this->weeklyLog($a, $batch, 'pending', $supervisor);
        $this->weeklyLog($b, $batch, 'approved', $supervisor);

        // Another supervisor's intern + pending log must not leak into counts.
        $other = User::factory()->create(['role' => 'supervisor']);
        $otherStudent = $this->intern($other, $batch, 'Outsider');
        $this->weeklyLog($otherStudent, $batch, 'pending', $other);

        Sanctum::actingAs($supervisor, ['*']);

        $response = $this->getJson('/api/supervisor/dashboard');

        $response->assertOk();
        $response->assertJsonPath('stats.my_interns', 2);
        $response->assertJsonPath('stats.pending_reviews', 1);
        $response->assertJsonPath('stats.approved_total', 1);
    }

    public function test_draft_weekly_log_not_counted_as_pending_review(): void
    {
        $program = $this->program();
        $batch = $this->batch($program);
        $supervisor = User::factory()->create(['role' => 'supervisor']);
        $student = $this->intern($supervisor, $batch, 'Drafter');

        // Not submitted => not a pending review.
        $this->weeklyLog($student, $batch, 'pending', null, submitted: false);

        Sanctum::actingAs($supervisor, ['*']);

        $this->getJson('/api/supervisor/dashboard')->assertJsonPath('stats.pending_reviews', 0);
    }

    public function test_non_supervisor_forbidden(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        Sanctum::actingAs($student, ['*']);

        $this->getJson('/api/supervisor/dashboard')->assertStatus(403);
        $this->getJson('/api/supervisor/interns')->assertStatus(403);
    }
}
