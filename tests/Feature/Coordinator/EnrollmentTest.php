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
        CompanySupervisor::create(['company_id' => $company->id, 'user_id' => $supervisor->id]);

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->postJson('/api/coordinator/enrollments', [
            'batch_id' => $batch->id,
            'student_id' => $student->id,
            'company_id' => $company->id,
            'assigned_division' => 'IT Department',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('batch_students', [
            'batch_id' => $batch->id,
            'student_id' => $student->id,
            'supervisor_id' => $supervisor->id,
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

        Sanctum::actingAs($intruder, ['*']);

        $response = $this->postJson('/api/coordinator/enrollments', [
            'batch_id' => $batch->id,
            'student_id' => $student->id,
            'company_id' => $company->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['batch_id']);
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
        CompanySupervisor::create(['company_id' => $newCompany->id, 'user_id' => $newSupervisor->id]);

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

    /**
     * The companies list stays unscoped (a company can be shared across
     * departments — the Enroll/Add-Intern forms may place a student at any
     * active company), so a company used exclusively by ANOTHER coordinator's
     * program still resolves its login_supervisor correctly here. This is the
     * regression guard for splitting that preview off the (now scoped)
     * supervisors list: before the split, scoping supervisors alone would have
     * silently broken this exact preview for a shared company.
     */
    public function test_a_shared_companys_login_supervisor_resolves_even_when_out_of_scope(): void
    {
        $ownProgram = $this->programFor('BSIT');
        $coordinator = User::factory()->create(['role' => 'coordinator', 'program_id' => $ownProgram->id]);
        $ownBatch = $this->batchFor($ownProgram, $coordinator);

        $otherDepartment = Department::create(['code' => 'CABM-B', 'name' => 'Business Department', 'is_active' => true]);
        $otherProgram = Program::create(['department_id' => $otherDepartment->id, 'code' => 'BSA', 'name' => 'BSA', 'is_active' => true]);
        $otherCoordinator = User::factory()->create(['role' => 'coordinator', 'program_id' => $otherProgram->id]);
        $otherBatch = $this->batchFor($otherProgram, $otherCoordinator);

        $sharedCompany = Company::create(['name' => 'Shared Co', 'address' => 'Addr S', 'is_active' => true]);
        $sharedSupervisor = User::factory()->create(['role' => 'supervisor', 'name' => 'Shared Co Supervisor']);
        CompanySupervisor::create(['company_id' => $sharedCompany->id, 'user_id' => $sharedSupervisor->id]);

        // Ties sharedCompany to the OTHER coordinator's program only, so it is
        // genuinely out of scope for $coordinator (not merely "unlinked").
        $otherStudent = User::factory()->create(['role' => 'student', 'program_id' => $otherProgram->id]);
        BatchStudent::create([
            'batch_id' => $otherBatch->id,
            'student_id' => $otherStudent->id,
            'company_id' => $sharedCompany->id,
            'supervisor_id' => $sharedSupervisor->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->getJson('/api/coordinator/enrollment-options');

        $response->assertOk();
        $companies = collect($response->json('companies'))->keyBy('id');

        $this->assertSame($sharedSupervisor->id, $companies[$sharedCompany->id]['login_supervisor']['id']);
    }

    /**
     * "Attach Existing Supervisor" must not leak accounts belonging to another
     * department's company — the actual privacy fix. It DOES still include a
     * supervisor already on one of the coordinator's own companies, and a
     * "floating" one attached to nothing at all (a fresh or just-detached
     * account), since scopedSupervisorIds() alone would wrongly hide those too.
     */
    public function test_the_attach_dropdown_excludes_a_supervisor_exclusive_to_another_department(): void
    {
        $ownProgram = $this->programFor('BSIT');
        $coordinator = User::factory()->create(['role' => 'coordinator', 'program_id' => $ownProgram->id]);
        $ownBatch = $this->batchFor($ownProgram, $coordinator);

        $ownCompany = Company::create(['name' => 'Own Co', 'address' => 'Addr O', 'is_active' => true]);
        $ownSupervisor = User::factory()->create(['role' => 'supervisor', 'name' => 'Own Co Supervisor']);
        CompanySupervisor::create(['company_id' => $ownCompany->id, 'user_id' => $ownSupervisor->id]);
        $ownStudent = User::factory()->create(['role' => 'student', 'program_id' => $ownProgram->id]);
        BatchStudent::create([
            'batch_id' => $ownBatch->id,
            'student_id' => $ownStudent->id,
            'company_id' => $ownCompany->id,
            'supervisor_id' => $ownSupervisor->id,
            'status' => 'active',
        ]);

        $otherDepartment = Department::create(['code' => 'CABM-B', 'name' => 'Business Department', 'is_active' => true]);
        $otherProgram = Program::create(['department_id' => $otherDepartment->id, 'code' => 'BSA', 'name' => 'BSA', 'is_active' => true]);
        $otherCoordinator = User::factory()->create(['role' => 'coordinator', 'program_id' => $otherProgram->id]);
        $otherBatch = $this->batchFor($otherProgram, $otherCoordinator);

        $otherCompany = Company::create(['name' => 'Other Co', 'address' => 'Addr X', 'is_active' => true]);
        $otherSupervisor = User::factory()->create(['role' => 'supervisor', 'name' => 'Other Co Supervisor']);
        CompanySupervisor::create(['company_id' => $otherCompany->id, 'user_id' => $otherSupervisor->id]);
        $otherStudent = User::factory()->create(['role' => 'student', 'program_id' => $otherProgram->id]);
        BatchStudent::create([
            'batch_id' => $otherBatch->id,
            'student_id' => $otherStudent->id,
            'company_id' => $otherCompany->id,
            'supervisor_id' => $otherSupervisor->id,
            'status' => 'active',
        ]);

        $floatingSupervisor = User::factory()->create(['role' => 'supervisor', 'name' => 'Floating Supervisor']);

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->getJson('/api/coordinator/enrollment-options');

        $response->assertOk();
        $supervisorIds = collect($response->json('supervisors'))->pluck('id');

        $this->assertTrue($supervisorIds->contains($ownSupervisor->id));
        $this->assertTrue($supervisorIds->contains($floatingSupervisor->id));
        $this->assertFalse($supervisorIds->contains($otherSupervisor->id));
    }

    /**
     * The dropdown narrows what is SHOWN; attachSupervisor() must independently
     * refuse an out-of-scope supervisor even if a client posts its id directly —
     * AttachSupervisorRequest alone only proves "this is some supervisor", not
     * that this coordinator may attach it.
     */
    public function test_attaching_a_supervisor_exclusive_to_another_department_is_refused(): void
    {
        $ownProgram = $this->programFor('BSIT');
        $coordinator = User::factory()->create(['role' => 'coordinator', 'program_id' => $ownProgram->id]);
        $ownBatch = $this->batchFor($ownProgram, $coordinator);
        $targetCompany = Company::create(['name' => 'Target Co', 'address' => 'Addr T', 'is_active' => true]);

        $otherDepartment = Department::create(['code' => 'CABM-B', 'name' => 'Business Department', 'is_active' => true]);
        $otherProgram = Program::create(['department_id' => $otherDepartment->id, 'code' => 'BSA', 'name' => 'BSA', 'is_active' => true]);
        $otherCoordinator = User::factory()->create(['role' => 'coordinator', 'program_id' => $otherProgram->id]);
        $otherBatch = $this->batchFor($otherProgram, $otherCoordinator);

        $otherCompany = Company::create(['name' => 'Other Co', 'address' => 'Addr X', 'is_active' => true]);
        $otherSupervisor = User::factory()->create(['role' => 'supervisor', 'name' => 'Other Co Supervisor']);
        CompanySupervisor::create(['company_id' => $otherCompany->id, 'user_id' => $otherSupervisor->id]);
        $otherStudent = User::factory()->create(['role' => 'student', 'program_id' => $otherProgram->id]);
        BatchStudent::create([
            'batch_id' => $otherBatch->id,
            'student_id' => $otherStudent->id,
            'company_id' => $otherCompany->id,
            'supervisor_id' => $otherSupervisor->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->postJson("/api/coordinator/companies/{$targetCompany->id}/supervisors", [
            'user_id' => $otherSupervisor->id,
            'position' => 'Lead',
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('company_supervisors', [
            'company_id' => $targetCompany->id,
            'user_id' => $otherSupervisor->id,
        ]);
    }
}
