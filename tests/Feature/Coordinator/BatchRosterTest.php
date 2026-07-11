<?php

namespace Tests\Feature\Coordinator;

use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\Company;
use App\Models\Department;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BatchRosterTest extends TestCase
{
    use RefreshDatabase;

    private function program(string $departmentCode, string $code): Program
    {
        $department = Department::firstOrCreate(
            ['code' => $departmentCode],
            ['name' => $departmentCode, 'is_active' => true]
        );

        return Program::firstOrCreate(
            ['department_id' => $department->id, 'code' => $code],
            ['name' => $code, 'is_active' => true]
        );
    }

    private function coordinatorFor(int $departmentId): User
    {
        $coordinator = User::factory()->create(['role' => 'coordinator']);
        $coordinator->departmentsCoordinated()->attach($departmentId);

        return $coordinator;
    }

    private function batchFor(Program $program, User $coordinator, string $name = 'Batch'): Batch
    {
        return Batch::create([
            'program_id' => $program->id,
            'coordinator_id' => $coordinator->id,
            'name' => $name.' '.uniqid(),
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

    private function company(): Company
    {
        return Company::create(['name' => 'Co '.uniqid(), 'address' => 'Bohol', 'is_active' => true]);
    }

    public function test_add_free_student_creates_active_row(): void
    {
        $program = $this->program('CABM-B', 'BSA');
        $coordinator = $this->coordinatorFor($program->department_id);
        $batch = $this->batchFor($program, $coordinator);
        $student = User::factory()->create(['role' => 'student', 'program_id' => $program->id]);
        $company = $this->company();
        $supervisor = User::factory()->create(['role' => 'supervisor']);

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->postJson("/api/coordinator/batches/{$batch->id}/roster", [
            'student_id' => $student->id,
            'company_id' => $company->id,
            'supervisor_id' => $supervisor->id,
        ]);

        $response->assertCreated()->assertJsonPath('moved', false);
        $this->assertDatabaseHas('batch_students', [
            'batch_id' => $batch->id,
            'student_id' => $student->id,
            'status' => 'active',
        ]);
    }

    public function test_add_already_enrolled_same_program_student_moves_them(): void
    {
        $program = $this->program('CABM-B', 'BSA');
        $coordinator = $this->coordinatorFor($program->department_id);
        $oldBatch = $this->batchFor($program, $coordinator, 'Old');
        $newBatch = $this->batchFor($program, $coordinator, 'New');
        $student = User::factory()->create(['role' => 'student', 'program_id' => $program->id]);
        $company = $this->company();
        $supervisor = User::factory()->create(['role' => 'supervisor']);

        $oldRow = BatchStudent::create([
            'batch_id' => $oldBatch->id,
            'student_id' => $student->id,
            'company_id' => $company->id,
            'supervisor_id' => $supervisor->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->postJson("/api/coordinator/batches/{$newBatch->id}/roster", [
            'student_id' => $student->id,
            'company_id' => $company->id,
            'supervisor_id' => $supervisor->id,
        ]);

        $response->assertCreated()->assertJsonPath('moved', true);
        $this->assertDatabaseHas('batch_students', ['id' => $oldRow->id, 'status' => 'dropped']);
        $this->assertDatabaseHas('batch_students', [
            'batch_id' => $newBatch->id,
            'student_id' => $student->id,
            'status' => 'active',
        ]);
        // Exactly one active row remains for the student.
        $this->assertSame(1, BatchStudent::where('student_id', $student->id)->where('status', 'active')->count());
    }

    public function test_cross_program_add_is_rejected(): void
    {
        $bsa = $this->program('CABM-B', 'BSA');
        $bsbaFm = $this->program('CABM-B', 'BSBA-FM'); // same department -> in scope
        $coordinator = $this->coordinatorFor($bsa->department_id);
        $batch = $this->batchFor($bsa, $coordinator);
        $student = User::factory()->create(['role' => 'student', 'program_id' => $bsbaFm->id]);
        $company = $this->company();
        $supervisor = User::factory()->create(['role' => 'supervisor']);

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->postJson("/api/coordinator/batches/{$batch->id}/roster", [
            'student_id' => $student->id,
            'company_id' => $company->id,
            'supervisor_id' => $supervisor->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['student_id']);
        $this->assertDatabaseMissing('batch_students', ['batch_id' => $batch->id, 'student_id' => $student->id]);
    }

    public function test_remove_marks_row_dropped(): void
    {
        $program = $this->program('CABM-B', 'BSA');
        $coordinator = $this->coordinatorFor($program->department_id);
        $batch = $this->batchFor($program, $coordinator);
        $student = User::factory()->create(['role' => 'student', 'program_id' => $program->id]);
        $company = $this->company();
        $supervisor = User::factory()->create(['role' => 'supervisor']);

        $row = BatchStudent::create([
            'batch_id' => $batch->id,
            'student_id' => $student->id,
            'company_id' => $company->id,
            'supervisor_id' => $supervisor->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($coordinator, ['*']);

        $this->patchJson("/api/coordinator/batches/{$batch->id}/roster/{$row->id}/drop")->assertOk();
        $this->assertDatabaseHas('batch_students', ['id' => $row->id, 'status' => 'dropped']);
    }

    public function test_delete_active_row_is_rejected(): void
    {
        $program = $this->program('CABM-B', 'BSA');
        $coordinator = $this->coordinatorFor($program->department_id);
        $batch = $this->batchFor($program, $coordinator);
        $student = User::factory()->create(['role' => 'student', 'program_id' => $program->id]);
        $company = $this->company();
        $supervisor = User::factory()->create(['role' => 'supervisor']);

        $row = BatchStudent::create([
            'batch_id' => $batch->id,
            'student_id' => $student->id,
            'company_id' => $company->id,
            'supervisor_id' => $supervisor->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($coordinator, ['*']);

        $this->deleteJson("/api/coordinator/batches/{$batch->id}/roster/{$row->id}")->assertStatus(422);
        $this->assertDatabaseHas('batch_students', ['id' => $row->id]);
    }

    public function test_delete_dropped_row_removes_it(): void
    {
        $program = $this->program('CABM-B', 'BSA');
        $coordinator = $this->coordinatorFor($program->department_id);
        $batch = $this->batchFor($program, $coordinator);
        $student = User::factory()->create(['role' => 'student', 'program_id' => $program->id]);
        $company = $this->company();
        $supervisor = User::factory()->create(['role' => 'supervisor']);

        $row = BatchStudent::create([
            'batch_id' => $batch->id,
            'student_id' => $student->id,
            'company_id' => $company->id,
            'supervisor_id' => $supervisor->id,
            'status' => 'dropped',
        ]);

        Sanctum::actingAs($coordinator, ['*']);

        $this->deleteJson("/api/coordinator/batches/{$batch->id}/roster/{$row->id}")->assertOk();
        $this->assertDatabaseMissing('batch_students', ['id' => $row->id]);
    }

    public function test_out_of_scope_batch_and_student_are_forbidden(): void
    {
        $inScope = $this->program('CABM-B', 'BSA');
        $coordinator = $this->coordinatorFor($inScope->department_id);
        $inBatch = $this->batchFor($inScope, $coordinator);
        $company = $this->company();
        $supervisor = User::factory()->create(['role' => 'supervisor']);

        // Out-of-scope batch (different department).
        $outProgram = $this->program('CABM-H', 'BSTM');
        $outCoordinator = User::factory()->create(['role' => 'coordinator']);
        $outBatch = $this->batchFor($outProgram, $outCoordinator);
        $outStudent = User::factory()->create(['role' => 'student', 'program_id' => $outProgram->id]);

        Sanctum::actingAs($coordinator, ['*']);

        // Touching a batch outside scope -> 403.
        $this->getJson("/api/coordinator/batches/{$outBatch->id}/roster")->assertStatus(403);

        // Adding an out-of-scope student into an in-scope batch -> 403.
        $this->postJson("/api/coordinator/batches/{$inBatch->id}/roster", [
            'student_id' => $outStudent->id,
            'company_id' => $company->id,
            'supervisor_id' => $supervisor->id,
        ])->assertStatus(403);
    }
}
