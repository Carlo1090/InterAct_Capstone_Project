<?php

namespace Tests\Feature\Coordinator;

use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\Company;
use App\Models\Department;
use App\Models\JournalEntry;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CoordinatorDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function programFor(string $code, string $deptCode = 'CAST'): Program
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

    private function coordinatorFor(Program $program): User
    {
        $coordinator = User::factory()->create(['role' => 'coordinator']);
        $coordinator->departmentsCoordinated()->attach($program->department_id);

        return $coordinator;
    }

    private function batchFor(Program $program, User $coordinator, bool $active = true): Batch
    {
        return Batch::create([
            'program_id' => $program->id,
            'coordinator_id' => $coordinator->id,
            'name' => 'Batch '.uniqid(),
            'start_date' => now()->subMonth(),
            'end_date' => now()->addMonth(),
            'required_hours' => 486,
            'working_days_per_week' => 5,
            'daily_reminder_time' => '21:00:00',
            'academic_year' => '2026',
            'semester' => 'Internship',
            'is_active' => $active,
        ]);
    }

    private function company(): Company
    {
        return Company::create(['name' => 'Co '.uniqid(), 'address' => 'Addr', 'is_active' => true]);
    }

    private function enroll(Batch $batch): BatchStudent
    {
        $student = User::factory()->create(['role' => 'student']);
        $supervisor = User::factory()->create(['role' => 'supervisor']);

        return BatchStudent::create([
            'batch_id' => $batch->id,
            'student_id' => $student->id,
            'company_id' => $this->company()->id,
            'supervisor_id' => $supervisor->id,
            'status' => 'active',
        ]);
    }

    private function entry(BatchStudent $enrollment, string $status, ?string $date = null): JournalEntry
    {
        return JournalEntry::create([
            'student_id' => $enrollment->student_id,
            'batch_id' => $enrollment->batch_id,
            'entry_date' => $date ?? now()->startOfWeek()->toDateString(),
            'content' => ['task_performed' => 'x'],
            'status' => $status,
        ]);
    }

    public function test_dashboard_returns_scoped_stats(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $batch = $this->batchFor($bsit, $coordinator);

        $a = $this->enroll($batch);
        $b = $this->enroll($batch);

        $this->entry($a, 'submitted');
        $this->entry($b, 'missing');

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->getJson('/api/coordinator/dashboard');

        $response->assertOk();
        $response->assertJsonPath('stats.active_interns', 2);
        $response->assertJsonPath('stats.journals_submitted_this_week', 1);
        $response->assertJsonPath('stats.journals_missing_this_week', 1);
        $response->assertJsonPath('stats.active_batches', 1);
        $response->assertJsonPath('stats.students_behind', 1);

        $behind = collect($response->json('students_behind'));
        $this->assertCount(1, $behind);
        $this->assertSame($b->student_id, $behind->first()['student_id']);
        $this->assertSame(1, $behind->first()['missing_count']);
    }

    public function test_dashboard_excludes_out_of_scope_data(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $this->batchFor($bsit, $coordinator);

        // Another department's batch + interns + missing entries.
        $bsba = $this->programFor('BSBA-FM', 'CABM-B');
        $otherCoord = $this->coordinatorFor($bsba);
        $otherBatch = $this->batchFor($bsba, $otherCoord);
        $otherEnrollment = $this->enroll($otherBatch);
        $this->entry($otherEnrollment, 'missing');

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->getJson('/api/coordinator/dashboard');

        $response->assertOk();
        // Only the in-scope (empty) data counts — the other department is excluded.
        $response->assertJsonPath('stats.active_interns', 0);
        $response->assertJsonPath('stats.journals_missing_this_week', 0);
        $response->assertJsonPath('stats.students_behind', 0);
        $response->assertJsonPath('stats.active_batches', 1);
    }
}
