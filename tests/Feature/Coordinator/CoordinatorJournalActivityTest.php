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

class CoordinatorJournalActivityTest extends TestCase
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

    private function batchFor(Program $program, User $coordinator): Batch
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
            'is_active' => true,
        ]);
    }

    private function enroll(Batch $batch, ?Company $company = null): BatchStudent
    {
        $student = User::factory()->create(['role' => 'student']);
        $supervisor = User::factory()->create(['role' => 'supervisor']);
        $company ??= Company::create(['name' => 'Co '.uniqid(), 'address' => 'Addr', 'is_active' => true]);

        return BatchStudent::create([
            'batch_id' => $batch->id,
            'student_id' => $student->id,
            'company_id' => $company->id,
            'supervisor_id' => $supervisor->id,
            'status' => 'active',
        ]);
    }

    private function entry(BatchStudent $enrollment, string $status, string $date): JournalEntry
    {
        return JournalEntry::create([
            'student_id' => $enrollment->student_id,
            'batch_id' => $enrollment->batch_id,
            'entry_date' => $date,
            'content' => ['task_performed' => 'x'],
            'status' => $status,
            'submitted_at' => $status === 'submitted' ? now() : null,
        ]);
    }

    public function test_default_view_is_today(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $batch = $this->batchFor($bsit, $coordinator);

        $submitted = $this->enroll($batch);
        $missing = $this->enroll($batch);
        $this->entry($submitted, 'submitted', now()->toDateString());
        // $missing has no entry today.

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->getJson('/api/coordinator/journal-activities');

        $response->assertOk();
        $response->assertJsonPath('is_single_day', true);
        $response->assertJsonPath('from', now()->toDateString());

        $rows = collect($response->json('rows'))->keyBy('student_id');
        $this->assertSame('submitted', $rows[$submitted->student_id]['day_status']);
        $this->assertSame('missing', $rows[$missing->student_id]['day_status']);
    }

    public function test_range_returns_submitted_and_missing_tallies(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $batch = $this->batchFor($bsit, $coordinator);
        $student = $this->enroll($batch);

        $this->entry($student, 'submitted', '2026-06-01');
        $this->entry($student, 'submitted', '2026-06-02');
        $this->entry($student, 'missing', '2026-06-03');
        // Outside the range — must not count.
        $this->entry($student, 'submitted', '2026-07-01');

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->getJson('/api/coordinator/journal-activities?from=2026-06-01&to=2026-06-30');

        $response->assertOk();
        $response->assertJsonPath('is_single_day', false);

        $row = collect($response->json('rows'))->firstWhere('student_id', $student->student_id);
        $this->assertSame(2, $row['submitted_count']);
        $this->assertSame(1, $row['missing_count']);
    }

    public function test_company_filter_narrows_rows(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $batch = $this->batchFor($bsit, $coordinator);

        $techph = Company::create(['name' => 'TechPH', 'address' => 'A', 'is_active' => true]);
        $prince = Company::create(['name' => 'Prince', 'address' => 'B', 'is_active' => true]);
        $a = $this->enroll($batch, $techph);
        $this->enroll($batch, $prince);

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->getJson("/api/coordinator/journal-activities?company_id={$techph->id}");

        $response->assertOk();
        $rows = collect($response->json('rows'));
        $this->assertCount(1, $rows);
        $this->assertSame($a->student_id, $rows->first()['student_id']);
    }

    public function test_out_of_scope_students_excluded(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $this->batchFor($bsit, $coordinator);

        $bsba = $this->programFor('BSBA-FM', 'CABM-B');
        $otherCoord = $this->coordinatorFor($bsba);
        $otherBatch = $this->batchFor($bsba, $otherCoord);
        $this->enroll($otherBatch);

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->getJson('/api/coordinator/journal-activities');

        $response->assertOk();
        $this->assertCount(0, $response->json('rows'));
    }
}
