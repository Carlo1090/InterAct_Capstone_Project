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

class CoordinatorUsersTest extends TestCase
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

    private function coordinatorFor(Program $program): User
    {
        $coordinator = User::factory()->create(['role' => 'coordinator', 'program_id' => $program->id]);
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

    public function test_interns_list_flags_enrolled_state_and_excludes_out_of_scope(): void
    {
        $inScope = $this->program('CABM-B', 'BSA');
        $coordinator = $this->coordinatorFor($inScope);
        $outScope = $this->program('CABM-H', 'BSTM');

        $company = Company::create(['name' => 'BQ Corp', 'address' => 'Bohol', 'is_active' => true]);
        $supervisor = User::factory()->create(['role' => 'supervisor']);
        $batch = $this->batchFor($inScope, $coordinator);

        $notEnrolled = User::factory()->create(['role' => 'student', 'program_id' => $inScope->id, 'name' => 'Not Enrolled Andrea']);
        $enrolled = User::factory()->create(['role' => 'student', 'program_id' => $inScope->id, 'name' => 'Enrolled Miguel']);
        $outside = User::factory()->create(['role' => 'student', 'program_id' => $outScope->id, 'name' => 'Out Of Scope Tessa']);

        BatchStudent::create([
            'batch_id' => $batch->id,
            'student_id' => $enrolled->id,
            'company_id' => $company->id,
            'supervisor_id' => $supervisor->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->getJson('/api/coordinator/users/interns');
        $response->assertOk();

        $rows = collect($response->json());
        $names = $rows->pluck('name');

        $this->assertTrue($names->contains('Not Enrolled Andrea'));
        $this->assertTrue($names->contains('Enrolled Miguel'));
        $this->assertFalse($names->contains('Out Of Scope Tessa'));

        $andrea = $rows->firstWhere('name', 'Not Enrolled Andrea');
        $this->assertFalse($andrea['enrolled']);
        $this->assertNull($andrea['enrollment']);

        $miguel = $rows->firstWhere('name', 'Enrolled Miguel');
        $this->assertTrue($miguel['enrolled']);
        $this->assertSame($batch->id, $miguel['enrollment']['batch']['id']);
    }

    public function test_interns_program_filter_rejects_out_of_scope_program(): void
    {
        $inScope = $this->program('CABM-B', 'BSA');
        $coordinator = $this->coordinatorFor($inScope);
        $outScope = $this->program('CABM-H', 'BSTM');

        Sanctum::actingAs($coordinator, ['*']);

        $this->getJson('/api/coordinator/users/interns?program_id='.$inScope->id)->assertOk();
        $this->getJson('/api/coordinator/users/interns?program_id='.$outScope->id)->assertStatus(403);
    }

    public function test_supervisors_list_unions_and_dedupes_within_scope(): void
    {
        $inScope = $this->program('CABM-B', 'BSA');
        $coordinator = $this->coordinatorFor($inScope);
        $outScope = $this->program('CABM-H', 'BSTM');
        $otherCoordinator = User::factory()->create(['role' => 'coordinator', 'program_id' => $outScope->id]);

        // Company used by an in-scope enrollment.
        $usedCompany = Company::create(['name' => 'Used In Scope Co', 'address' => 'Bohol', 'is_active' => true]);
        // Companies not linked to any enrollment (in scope via the unlinked set).
        $unlinkedA = Company::create(['name' => 'Unlinked A Co', 'address' => 'Bohol', 'is_active' => true]);
        $unlinkedB = Company::create(['name' => 'Unlinked B Co', 'address' => 'Bohol', 'is_active' => true]);
        // Company used by an OUT-OF-SCOPE enrollment (linked, so not "unlinked").
        $outCompany = Company::create(['name' => 'Out Of Scope Co', 'address' => 'Bohol', 'is_active' => true]);

        $supA = User::factory()->create(['role' => 'supervisor', 'name' => 'Union Supervisor A']);
        $supB = User::factory()->create(['role' => 'supervisor', 'name' => 'Created Supervisor B']);
        $supC = User::factory()->create(['role' => 'supervisor', 'name' => 'Out Of Scope Supervisor C']);

        // supA is attached to both a used and an unlinked company -> must dedupe.
        CompanySupervisor::create(['company_id' => $usedCompany->id, 'user_id' => $supA->id, 'position' => 'Lead']);
        CompanySupervisor::create(['company_id' => $unlinkedA->id, 'user_id' => $supA->id, 'position' => 'Adviser']);
        // supB represents a coordinator-created supervisor on a still-unlinked company.
        CompanySupervisor::create(['company_id' => $unlinkedB->id, 'user_id' => $supB->id, 'position' => 'Director']);
        // supC only on the out-of-scope company.
        CompanySupervisor::create(['company_id' => $outCompany->id, 'user_id' => $supC->id, 'position' => 'Manager']);

        // The in-scope enrollment that links $usedCompany into scope.
        $inBatch = $this->batchFor($inScope, $coordinator);
        $inStudent = User::factory()->create(['role' => 'student', 'program_id' => $inScope->id]);
        BatchStudent::create(['batch_id' => $inBatch->id, 'student_id' => $inStudent->id, 'company_id' => $usedCompany->id, 'supervisor_id' => $supA->id, 'status' => 'active']);

        // The out-of-scope enrollment that links $outCompany (kept out of scope).
        $outBatch = $this->batchFor($outScope, $otherCoordinator);
        $outStudent = User::factory()->create(['role' => 'student', 'program_id' => $outScope->id]);
        BatchStudent::create(['batch_id' => $outBatch->id, 'student_id' => $outStudent->id, 'company_id' => $outCompany->id, 'supervisor_id' => $supC->id, 'status' => 'active']);

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->getJson('/api/coordinator/users/supervisors');
        $response->assertOk();

        $rows = collect($response->json());
        $names = $rows->pluck('name');

        $this->assertTrue($names->contains('Union Supervisor A'));
        $this->assertTrue($names->contains('Created Supervisor B'));
        $this->assertFalse($names->contains('Out Of Scope Supervisor C'));

        // Deduped to a single row carrying both in-scope companies.
        $this->assertSame(1, $names->filter(fn ($name) => $name === 'Union Supervisor A')->count());
        $rowA = $rows->firstWhere('name', 'Union Supervisor A');
        $companyNames = collect($rowA['companies'])->pluck('name');
        $this->assertTrue($companyNames->contains('Used In Scope Co'));
        $this->assertTrue($companyNames->contains('Unlinked A Co'));
    }

    public function test_non_coordinator_cannot_access_users_lists(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        Sanctum::actingAs($student, ['*']);

        $this->getJson('/api/coordinator/users/interns')->assertStatus(403);
        $this->getJson('/api/coordinator/users/supervisors')->assertStatus(403);
    }
}
