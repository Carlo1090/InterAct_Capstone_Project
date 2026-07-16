<?php

namespace Tests\Feature\Coordinator;

use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\Company;
use App\Models\CompanySupervisor;
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
        CompanySupervisor::create(['company_id' => $company->id, 'user_id' => $supervisor->id]);

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->postJson("/api/coordinator/batches/{$batch->id}/roster", [
            'student_id' => $student->id,
            'company_id' => $company->id,
        ]);

        $response->assertCreated()->assertJsonPath('moved', false);
        $this->assertDatabaseHas('batch_students', [
            'batch_id' => $batch->id,
            'student_id' => $student->id,
            'supervisor_id' => $supervisor->id,
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
        CompanySupervisor::create(['company_id' => $company->id, 'user_id' => $supervisor->id]);

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

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->postJson("/api/coordinator/batches/{$batch->id}/roster", [
            'student_id' => $student->id,
            'company_id' => $company->id,
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

    public function test_delete_dropped_but_unarchived_row_is_rejected(): void
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

        $this->deleteJson("/api/coordinator/batches/{$batch->id}/roster/{$row->id}")->assertStatus(422);
        $this->assertDatabaseHas('batch_students', ['id' => $row->id]);
    }

    public function test_delete_archived_row_removes_it(): void
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
        $row->archived_at = now();
        $row->save();

        Sanctum::actingAs($coordinator, ['*']);

        $this->deleteJson("/api/coordinator/batches/{$batch->id}/roster/{$row->id}")->assertOk();
        $this->assertDatabaseMissing('batch_students', ['id' => $row->id]);
    }

    public function test_archive_dropped_row_stamps_archived_at(): void
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

        $this->patchJson("/api/coordinator/batches/{$batch->id}/roster/{$row->id}/archive")->assertOk();

        $fresh = $row->fresh();
        $this->assertNotNull($fresh->archived_at);
        $this->assertSame('dropped', $fresh->status);
    }

    public function test_archive_completed_row_stamps_archived_at(): void
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
            'status' => 'completed',
        ]);

        Sanctum::actingAs($coordinator, ['*']);

        $this->patchJson("/api/coordinator/batches/{$batch->id}/roster/{$row->id}/archive")->assertOk();

        $fresh = $row->fresh();
        $this->assertNotNull($fresh->archived_at);
        $this->assertSame('completed', $fresh->status);
    }

    public function test_archive_active_row_is_rejected(): void
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

        $this->patchJson("/api/coordinator/batches/{$batch->id}/roster/{$row->id}/archive")->assertStatus(422);
        $this->assertNull($row->fresh()->archived_at);
    }

    public function test_archive_already_archived_row_is_rejected(): void
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
        $row->archived_at = now();
        $row->save();

        Sanctum::actingAs($coordinator, ['*']);

        $this->patchJson("/api/coordinator/batches/{$batch->id}/roster/{$row->id}/archive")->assertStatus(422);
    }

    public function test_restore_clears_archived_at_and_keeps_status(): void
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
        $row->archived_at = now();
        $row->save();

        Sanctum::actingAs($coordinator, ['*']);

        $this->patchJson("/api/coordinator/batches/{$batch->id}/roster/{$row->id}/restore")->assertOk();

        $fresh = $row->fresh();
        $this->assertNull($fresh->archived_at);
        $this->assertSame('dropped', $fresh->status);
    }

    public function test_restore_non_archived_row_is_rejected(): void
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

        $this->patchJson("/api/coordinator/batches/{$batch->id}/roster/{$row->id}/restore")->assertStatus(422);
    }

    public function test_reactivate_archived_row_is_rejected(): void
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
        $row->archived_at = now();
        $row->save();

        Sanctum::actingAs($coordinator, ['*']);

        $this->patchJson("/api/coordinator/batches/{$batch->id}/roster/{$row->id}/reactivate")->assertStatus(422);

        $fresh = $row->fresh();
        $this->assertSame('dropped', $fresh->status);
        $this->assertNotNull($fresh->archived_at);
    }

    public function test_reopen_archived_row_is_rejected(): void
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
            'status' => 'completed',
        ]);
        $row->archived_at = now();
        $row->save();

        Sanctum::actingAs($coordinator, ['*']);

        $this->patchJson("/api/coordinator/batches/{$batch->id}/roster/{$row->id}/reopen")->assertStatus(422);

        $fresh = $row->fresh();
        $this->assertSame('completed', $fresh->status);
        $this->assertNotNull($fresh->archived_at);
    }

    /**
     * Regression: archive -> reactivate used to be reachable (reactivate only
     * checked status, not archived_at), producing an active-but-archived row
     * that destroy()'s archived_at-only guard would then let through — even
     * though an active row must never be directly deletable.
     */
    public function test_reactivate_then_destroy_on_a_formerly_archived_row_stays_blocked(): void
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
        $row->archived_at = now();
        $row->save();

        Sanctum::actingAs($coordinator, ['*']);

        // Reactivate is rejected while archived...
        $this->patchJson("/api/coordinator/batches/{$batch->id}/roster/{$row->id}/reactivate")->assertStatus(422);

        // ...restore, then reactivate succeeds and clears the archive...
        $this->patchJson("/api/coordinator/batches/{$batch->id}/roster/{$row->id}/restore")->assertOk();
        $this->patchJson("/api/coordinator/batches/{$batch->id}/roster/{$row->id}/reactivate")->assertOk();

        $fresh = $row->fresh();
        $this->assertSame('active', $fresh->status);
        $this->assertNull($fresh->archived_at);

        // ...and an active, non-archived row can never be deleted directly.
        $this->deleteJson("/api/coordinator/batches/{$batch->id}/roster/{$row->id}")->assertStatus(422);
        $this->assertDatabaseHas('batch_students', ['id' => $row->id]);
    }

    public function test_archive_out_of_scope_batch_is_forbidden(): void
    {
        $inScope = $this->program('CABM-B', 'BSA');
        $coordinator = $this->coordinatorFor($inScope->department_id);

        $outProgram = $this->program('CABM-H', 'BSTM');
        $outCoordinator = User::factory()->create(['role' => 'coordinator']);
        $outBatch = $this->batchFor($outProgram, $outCoordinator);
        $outStudent = User::factory()->create(['role' => 'student', 'program_id' => $outProgram->id]);
        $company = $this->company();
        $supervisor = User::factory()->create(['role' => 'supervisor']);

        $row = BatchStudent::create([
            'batch_id' => $outBatch->id,
            'student_id' => $outStudent->id,
            'company_id' => $company->id,
            'supervisor_id' => $supervisor->id,
            'status' => 'dropped',
        ]);

        Sanctum::actingAs($coordinator, ['*']);

        $this->patchJson("/api/coordinator/batches/{$outBatch->id}/roster/{$row->id}/archive")->assertStatus(403);
    }

    public function test_reactivate_flips_dropped_row_back_to_active(): void
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

        $response = $this->patchJson("/api/coordinator/batches/{$batch->id}/roster/{$row->id}/reactivate");

        $response->assertOk();
        $this->assertDatabaseHas('batch_students', ['id' => $row->id, 'status' => 'active']);
    }

    public function test_reactivate_is_blocked_when_student_is_active_elsewhere(): void
    {
        $program = $this->program('CABM-B', 'BSA');
        $coordinator = $this->coordinatorFor($program->department_id);
        $droppedBatch = $this->batchFor($program, $coordinator, 'Dropped Batch');
        $activeBatch = $this->batchFor($program, $coordinator, 'Active Batch');
        $student = User::factory()->create(['role' => 'student', 'program_id' => $program->id]);
        $company = $this->company();
        $supervisor = User::factory()->create(['role' => 'supervisor']);

        $droppedRow = BatchStudent::create([
            'batch_id' => $droppedBatch->id,
            'student_id' => $student->id,
            'company_id' => $company->id,
            'supervisor_id' => $supervisor->id,
            'status' => 'dropped',
        ]);

        BatchStudent::create([
            'batch_id' => $activeBatch->id,
            'student_id' => $student->id,
            'company_id' => $company->id,
            'supervisor_id' => $supervisor->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->patchJson("/api/coordinator/batches/{$droppedBatch->id}/roster/{$droppedRow->id}/reactivate");

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'This student is already active in "'.$activeBatch->name.'". Use Add or Move on that batch instead of reactivating this dropped record.']);
        $this->assertDatabaseHas('batch_students', ['id' => $droppedRow->id, 'status' => 'dropped']);
    }

    public function test_reactivate_active_row_is_rejected(): void
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

        $this->patchJson("/api/coordinator/batches/{$batch->id}/roster/{$row->id}/reactivate")->assertStatus(422);
    }

    public function test_reactivate_out_of_scope_batch_is_forbidden(): void
    {
        $inScope = $this->program('CABM-B', 'BSA');
        $coordinator = $this->coordinatorFor($inScope->department_id);

        $outProgram = $this->program('CABM-H', 'BSTM');
        $outCoordinator = User::factory()->create(['role' => 'coordinator']);
        $outBatch = $this->batchFor($outProgram, $outCoordinator);
        $outStudent = User::factory()->create(['role' => 'student', 'program_id' => $outProgram->id]);
        $company = $this->company();
        $supervisor = User::factory()->create(['role' => 'supervisor']);

        $row = BatchStudent::create([
            'batch_id' => $outBatch->id,
            'student_id' => $outStudent->id,
            'company_id' => $company->id,
            'supervisor_id' => $supervisor->id,
            'status' => 'dropped',
        ]);

        Sanctum::actingAs($coordinator, ['*']);

        $this->patchJson("/api/coordinator/batches/{$outBatch->id}/roster/{$row->id}/reactivate")->assertStatus(403);
    }

    public function test_complete_marks_active_row_completed_and_stamps_completed_at(): void
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

        $response = $this->patchJson("/api/coordinator/batches/{$batch->id}/roster/{$row->id}/complete");

        $response->assertOk()->assertJsonPath('status', 'completed');
        $this->assertNotNull($row->fresh()->completed_at);
    }

    public function test_complete_rejects_a_non_active_row(): void
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

        $this->patchJson("/api/coordinator/batches/{$batch->id}/roster/{$row->id}/complete")->assertStatus(422);
    }

    public function test_reopen_restores_active_and_clears_completed_at(): void
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
        $row->update(['status' => 'completed']);
        $this->assertNotNull($row->fresh()->completed_at);

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->patchJson("/api/coordinator/batches/{$batch->id}/roster/{$row->id}/reopen");

        $response->assertOk()->assertJsonPath('status', 'active');
        $this->assertNull($row->fresh()->completed_at);
    }

    public function test_reopen_is_blocked_when_student_is_active_elsewhere(): void
    {
        $program = $this->program('CABM-B', 'BSA');
        $coordinator = $this->coordinatorFor($program->department_id);
        $completedBatch = $this->batchFor($program, $coordinator, 'Completed Batch');
        $activeBatch = $this->batchFor($program, $coordinator, 'Active Batch');
        $student = User::factory()->create(['role' => 'student', 'program_id' => $program->id]);
        $company = $this->company();
        $supervisor = User::factory()->create(['role' => 'supervisor']);

        $completedRow = BatchStudent::create([
            'batch_id' => $completedBatch->id,
            'student_id' => $student->id,
            'company_id' => $company->id,
            'supervisor_id' => $supervisor->id,
            'status' => 'completed',
        ]);

        BatchStudent::create([
            'batch_id' => $activeBatch->id,
            'student_id' => $student->id,
            'company_id' => $company->id,
            'supervisor_id' => $supervisor->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->patchJson("/api/coordinator/batches/{$completedBatch->id}/roster/{$completedRow->id}/reopen");

        $response->assertStatus(422);
        $this->assertDatabaseHas('batch_students', ['id' => $completedRow->id, 'status' => 'completed']);
    }

    public function test_reopen_rejects_a_non_completed_row(): void
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

        $this->patchJson("/api/coordinator/batches/{$batch->id}/roster/{$row->id}/reopen")->assertStatus(422);
    }

    public function test_out_of_scope_batch_and_student_are_forbidden(): void
    {
        $inScope = $this->program('CABM-B', 'BSA');
        $coordinator = $this->coordinatorFor($inScope->department_id);
        $inBatch = $this->batchFor($inScope, $coordinator);
        $company = $this->company();

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
        ])->assertStatus(403);
    }
}
