<?php

namespace Tests\Feature\Coordinator;

use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\Company;
use App\Models\Department;
use App\Models\Program;
use App\Models\StudentInformationSheet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CoordinatorInfoSheetTest extends TestCase
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

    private function enrolledStudent(Batch $batch, string $name): User
    {
        $student = User::factory()->create(['role' => 'student', 'name' => $name]);
        $supervisor = User::factory()->create(['role' => 'supervisor']);
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

    public function test_index_lists_only_in_scope_students(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $batch = $this->batchFor($bsit, $coordinator);
        $inScope = $this->enrolledStudent($batch, 'Ana Cruz');
        StudentInformationSheet::create([
            'student_id' => $inScope->id,
            'batch_id' => $batch->id,
            'personal_info' => ['first_name' => 'Ana'],
            'academic_info' => ['program_course' => 'BSIT'],
            'ojt_info' => ['host_company' => 'Co'],
            'submission_status' => 'submitted',
        ]);

        // Out-of-scope student in another department.
        $bsba = $this->programFor('BSBA-FM', 'CABM-B');
        $otherCoord = $this->coordinatorFor($bsba);
        $otherBatch = $this->batchFor($bsba, $otherCoord);
        $this->enrolledStudent($otherBatch, 'Ben Reyes');

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->getJson('/api/coordinator/info-sheets');

        $response->assertOk();
        $names = collect($response->json('students'))->pluck('name');
        $this->assertTrue($names->contains('Ana Cruz'));
        $this->assertFalse($names->contains('Ben Reyes'));

        $row = collect($response->json('students'))->firstWhere('student_id', $inScope->id);
        $this->assertSame('submitted', $row['submission_status']);
    }

    public function test_show_returns_in_scope_sheet(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $batch = $this->batchFor($bsit, $coordinator);
        $student = $this->enrolledStudent($batch, 'Ana Cruz');
        StudentInformationSheet::create([
            'student_id' => $student->id,
            'batch_id' => $batch->id,
            'personal_info' => ['first_name' => 'Ana', 'last_name' => 'Cruz'],
            'academic_info' => ['program_course' => 'BSIT'],
            'ojt_info' => ['host_company' => 'Co'],
            'submission_status' => 'submitted',
        ]);

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->getJson("/api/coordinator/info-sheets/{$student->id}");

        $response->assertOk();
        $response->assertJsonPath('student.id', $student->id);
        $response->assertJsonPath('sheet.submission_status', 'submitted');
    }

    public function test_show_out_of_scope_student_returns_403(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);

        $bsba = $this->programFor('BSBA-FM', 'CABM-B');
        $otherCoord = $this->coordinatorFor($bsba);
        $otherBatch = $this->batchFor($bsba, $otherCoord);
        $outStudent = $this->enrolledStudent($otherBatch, 'Ben Reyes');

        Sanctum::actingAs($coordinator, ['*']);

        $this->getJson("/api/coordinator/info-sheets/{$outStudent->id}")->assertStatus(403);
    }
}
