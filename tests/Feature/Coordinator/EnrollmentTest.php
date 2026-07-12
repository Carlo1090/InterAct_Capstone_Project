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

class EnrollmentTest extends TestCase
{
    use RefreshDatabase;

    private function programFor(string $code): Program
    {
        $department = Department::firstOrCreate(
            ['code' => 'CAST'],
            ['name' => 'College of Arts, Sciences and Technology', 'is_active' => true]
        );

        return Program::firstOrCreate(
            ['department_id' => $department->id, 'code' => $code],
            ['name' => $code, 'is_active' => true]
        );
    }

    private function batchFor(Program $program, User $coordinator, array $overrides = []): Batch
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
            ...$overrides,
        ]);
    }

    public function test_coordinator_enrolls_a_fresh_student_and_activates_it(): void
    {
        $program = $this->programFor('BSIT');
        $coordinator = User::factory()->create(['role' => 'coordinator', 'program_id' => $program->id]);
        $batch = $this->batchFor($program, $coordinator);
        $student = User::factory()->create(['role' => 'student', 'program_id' => $program->id]);
        $company = Company::create(['name' => 'TechPH Inc.', 'address' => 'Cebu City', 'is_active' => true]);
        $supervisor = User::factory()->create(['role' => 'supervisor']);

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->postJson('/api/coordinator/enrollments', [
            'batch_id' => $batch->id,
            'student_id' => $student->id,
            'company_id' => $company->id,
            'supervisor_id' => $supervisor->id,
            'assigned_division' => 'IT Department',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('batch_students', [
            'batch_id' => $batch->id,
            'student_id' => $student->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($student, ['*']);
        $infoSheetResponse = $this->getJson('/api/student/info-sheet');
        $infoSheetResponse->assertOk()->assertJsonPath('ojt_info.host_company', 'TechPH Inc.');
    }

    public function test_coordinator_cannot_enroll_into_another_coordinators_batch(): void
    {
        $program = $this->programFor('BSIT');
        $owner = User::factory()->create(['role' => 'coordinator', 'program_id' => $program->id]);
        $intruder = User::factory()->create(['role' => 'coordinator', 'program_id' => $program->id]);
        $batch = $this->batchFor($program, $owner);
        $student = User::factory()->create(['role' => 'student', 'program_id' => $program->id]);
        $company = Company::create(['name' => 'TechPH Inc.', 'address' => 'Cebu City', 'is_active' => true]);
        $supervisor = User::factory()->create(['role' => 'supervisor']);

        Sanctum::actingAs($intruder, ['*']);

        $response = $this->postJson('/api/coordinator/enrollments', [
            'batch_id' => $batch->id,
            'student_id' => $student->id,
            'company_id' => $company->id,
            'supervisor_id' => $supervisor->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['batch_id']);
    }

    public function test_non_supervisor_cannot_be_assigned_as_supervisor(): void
    {
        $program = $this->programFor('BSIT');
        $coordinator = User::factory()->create(['role' => 'coordinator', 'program_id' => $program->id]);
        $batch = $this->batchFor($program, $coordinator);
        $student = User::factory()->create(['role' => 'student', 'program_id' => $program->id]);
        $company = Company::create(['name' => 'TechPH Inc.', 'address' => 'Cebu City', 'is_active' => true]);
        $notASupervisor = User::factory()->create(['role' => 'student']);

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->postJson('/api/coordinator/enrollments', [
            'batch_id' => $batch->id,
            'student_id' => $student->id,
            'company_id' => $company->id,
            'supervisor_id' => $notASupervisor->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['supervisor_id']);
    }

    public function test_duplicate_active_enrollment_is_rejected(): void
    {
        $program = $this->programFor('BSIT');
        $coordinator = User::factory()->create(['role' => 'coordinator', 'program_id' => $program->id]);
        $batch = $this->batchFor($program, $coordinator);
        $student = User::factory()->create(['role' => 'student', 'program_id' => $program->id]);
        $company = Company::create(['name' => 'TechPH Inc.', 'address' => 'Cebu City', 'is_active' => true]);
        $supervisor = User::factory()->create(['role' => 'supervisor']);

        BatchStudent::create([
            'batch_id' => $batch->id,
            'student_id' => $student->id,
            'company_id' => $company->id,
            'supervisor_id' => $supervisor->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->postJson('/api/coordinator/enrollments', [
            'batch_id' => $batch->id,
            'student_id' => $student->id,
            'company_id' => $company->id,
            'supervisor_id' => $supervisor->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['student_id']);
    }

    public function test_roster_applies_batch_and_status_filters_together_and_excludes_other_coordinators(): void
    {
        $program = $this->programFor('BSIT');
        $coordinator = User::factory()->create(['role' => 'coordinator', 'program_id' => $program->id]);
        $otherCoordinator = User::factory()->create(['role' => 'coordinator', 'program_id' => $program->id]);

        $batch1 = $this->batchFor($program, $coordinator, ['name' => 'Batch 1']);
        $batch2 = $this->batchFor($program, $coordinator, ['name' => 'Batch 2']);
        $otherBatch = $this->batchFor($program, $otherCoordinator, ['name' => 'Other Coordinator Batch']);

        $company = Company::create(['name' => 'TechPH Inc.', 'address' => 'Cebu City', 'is_active' => true]);
        $supervisor = User::factory()->create(['role' => 'supervisor']);

        $activeInBatch1 = User::factory()->create(['role' => 'student', 'program_id' => $program->id, 'name' => 'Active Batch1']);
        $droppedInBatch1 = User::factory()->create(['role' => 'student', 'program_id' => $program->id, 'name' => 'Dropped Batch1']);
        $activeInBatch2 = User::factory()->create(['role' => 'student', 'program_id' => $program->id, 'name' => 'Active Batch2']);
        $otherCoordinatorStudent = User::factory()->create(['role' => 'student', 'program_id' => $program->id, 'name' => 'Other Coordinator Student']);

        BatchStudent::create(['batch_id' => $batch1->id, 'student_id' => $activeInBatch1->id, 'company_id' => $company->id, 'supervisor_id' => $supervisor->id, 'status' => 'active']);
        BatchStudent::create(['batch_id' => $batch1->id, 'student_id' => $droppedInBatch1->id, 'company_id' => $company->id, 'supervisor_id' => $supervisor->id, 'status' => 'dropped']);
        BatchStudent::create(['batch_id' => $batch2->id, 'student_id' => $activeInBatch2->id, 'company_id' => $company->id, 'supervisor_id' => $supervisor->id, 'status' => 'active']);
        BatchStudent::create(['batch_id' => $otherBatch->id, 'student_id' => $otherCoordinatorStudent->id, 'company_id' => $company->id, 'supervisor_id' => $supervisor->id, 'status' => 'active']);

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->getJson("/api/coordinator/roster?batch_id={$batch1->id}&status=active");

        $response->assertOk();
        $names = collect($response->json('students'))->pluck('student.name');

        $this->assertTrue($names->contains('Active Batch1'));
        $this->assertFalse($names->contains('Dropped Batch1'));
        $this->assertFalse($names->contains('Active Batch2'));
        $this->assertFalse($names->contains('Other Coordinator Student'));

        $filterBatchNames = collect($response->json('filters.batches'))->pluck('name');
        $this->assertTrue($filterBatchNames->contains('Batch 1'));
        $this->assertTrue($filterBatchNames->contains('Batch 2'));
        $this->assertFalse($filterBatchNames->contains('Other Coordinator Batch'));
    }

    public function test_re_enrolling_a_dropped_student_reactivates_the_row_instead_of_duplicating(): void
    {
        $program = $this->programFor('BSIT');
        $coordinator = User::factory()->create(['role' => 'coordinator', 'program_id' => $program->id]);
        $batch = $this->batchFor($program, $coordinator);
        $student = User::factory()->create(['role' => 'student', 'program_id' => $program->id]);
        $oldCompany = Company::create(['name' => 'Old Co', 'address' => 'A', 'is_active' => true]);
        $newCompany = Company::create(['name' => 'New Co', 'address' => 'B', 'is_active' => true]);
        $oldSupervisor = User::factory()->create(['role' => 'supervisor']);
        $newSupervisor = User::factory()->create(['role' => 'supervisor']);

        $droppedRow = BatchStudent::create([
            'batch_id' => $batch->id,
            'student_id' => $student->id,
            'company_id' => $oldCompany->id,
            'supervisor_id' => $oldSupervisor->id,
            'assigned_division' => 'Old Division',
            'status' => 'dropped',
        ]);

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->postJson('/api/coordinator/enrollments', [
            'batch_id' => $batch->id,
            'student_id' => $student->id,
            'company_id' => $newCompany->id,
            'supervisor_id' => $newSupervisor->id,
            'assigned_division' => 'New Division',
        ]);

        $response->assertOk();
        $this->assertSame(1, BatchStudent::where('batch_id', $batch->id)->where('student_id', $student->id)->count());
        $this->assertDatabaseHas('batch_students', [
            'id' => $droppedRow->id,
            'status' => 'active',
            'company_id' => $newCompany->id,
            'supervisor_id' => $newSupervisor->id,
            'assigned_division' => 'New Division',
        ]);
    }

    public function test_options_supervisors_carry_their_company_ids(): void
    {
        $program = $this->programFor('BSIT');
        $coordinator = User::factory()->create(['role' => 'coordinator', 'program_id' => $program->id]);

        $companyA = Company::create(['name' => 'Alpha Co', 'address' => 'Addr A', 'is_active' => true]);
        $companyB = Company::create(['name' => 'Beta Co', 'address' => 'Addr B', 'is_active' => true]);

        $supervisorInBoth = User::factory()->create(['role' => 'supervisor', 'name' => 'Multi Supervisor']);
        CompanySupervisor::create(['company_id' => $companyA->id, 'user_id' => $supervisorInBoth->id, 'position' => 'Lead']);
        CompanySupervisor::create(['company_id' => $companyB->id, 'user_id' => $supervisorInBoth->id, 'position' => 'Lead']);

        $supervisorInA = User::factory()->create(['role' => 'supervisor', 'name' => 'Alpha-Only Supervisor']);
        CompanySupervisor::create(['company_id' => $companyA->id, 'user_id' => $supervisorInA->id, 'position' => 'Staff']);

        $unassignedSupervisor = User::factory()->create(['role' => 'supervisor', 'name' => 'Unassigned Supervisor']);

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->getJson('/api/coordinator/enrollment-options');

        $response->assertOk();
        $supervisors = collect($response->json('supervisors'))->keyBy('id');

        $this->assertEqualsCanonicalizing(
            [$companyA->id, $companyB->id],
            $supervisors[$supervisorInBoth->id]['company_ids']
        );
        $this->assertEqualsCanonicalizing([$companyA->id], $supervisors[$supervisorInA->id]['company_ids']);
        $this->assertSame([], $supervisors[$unassignedSupervisor->id]['company_ids']);
    }
}
