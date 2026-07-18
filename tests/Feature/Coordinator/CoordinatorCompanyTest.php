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

class CoordinatorCompanyTest extends TestCase
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

    /** A company linked to an enrollment in the given batch (so it is "used"). */
    private function usedCompany(Batch $batch, string $name): Company
    {
        $company = Company::create(['name' => $name, 'address' => 'Addr', 'is_active' => true]);
        $student = User::factory()->create(['role' => 'student']);
        $supervisor = User::factory()->create(['role' => 'supervisor']);
        BatchStudent::create([
            'batch_id' => $batch->id,
            'student_id' => $student->id,
            'company_id' => $company->id,
            'supervisor_id' => $supervisor->id,
            'status' => 'active',
        ]);

        return $company;
    }

    public function test_coordinator_creates_company_and_sees_it_in_scoped_list(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);

        Sanctum::actingAs($coordinator, ['*']);

        $this->postJson('/api/coordinator/companies', [
            'name' => 'New HTE Inc.',
            'address' => '123 Street, Bohol',
            'location' => 'Tagbilaran',
            'industry' => 'Tech',
            'contact_number' => null,
            'description' => 'A freshly created partner.',
        ])->assertCreated();

        $names = collect($this->getJson('/api/coordinator/companies')->json())->pluck('name');
        $this->assertTrue($names->contains('New HTE Inc.'));
    }

    public function test_scoped_list_includes_used_and_excludes_out_of_scope(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $batch = $this->batchFor($bsit, $coordinator);
        $inScope = $this->usedCompany($batch, 'InScope Co');

        // A company used only by another department's students.
        $bsba = $this->programFor('BSBA-FM', 'CABM-B');
        $otherCoord = $this->coordinatorFor($bsba);
        $otherBatch = $this->batchFor($bsba, $otherCoord);
        $outScope = $this->usedCompany($otherBatch, 'OutScope Co');

        Sanctum::actingAs($coordinator, ['*']);

        $names = collect($this->getJson('/api/coordinator/companies')->json())->pluck('name');
        $this->assertTrue($names->contains('InScope Co'));
        $this->assertFalse($names->contains('OutScope Co'));

        // Direct access to the out-of-scope company is forbidden.
        $this->getJson("/api/coordinator/companies/{$outScope->id}")->assertStatus(403);
        $this->putJson("/api/coordinator/companies/{$outScope->id}", ['name' => 'x'])->assertStatus(403);
        $this->assertNotNull($inScope);
    }

    public function test_attach_existing_supervisor(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $company = Company::create(['name' => 'Fresh Co', 'address' => 'A', 'is_active' => true]);
        $supervisor = User::factory()->create(['role' => 'supervisor']);

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->postJson("/api/coordinator/companies/{$company->id}/supervisors", [
            'user_id' => $supervisor->id,
            'position' => 'Team Lead',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('company_supervisors', [
            'company_id' => $company->id,
            'user_id' => $supervisor->id,
            'position' => 'Team Lead',
        ]);
    }

    public function test_create_and_attach_new_supervisor(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $company = Company::create(['name' => 'Fresh Co', 'address' => 'A', 'is_active' => true]);

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->postJson("/api/coordinator/companies/{$company->id}/supervisors/new", [
            'name' => 'New Supervisor',
            'email' => 'newsup@example.com',
            'password' => 'password123',
            'position' => 'Manager',
        ]);

        $response->assertCreated();

        $supervisor = User::where('email', 'newsup@example.com')->first();
        $this->assertNotNull($supervisor);
        $this->assertSame('supervisor', $supervisor->role);
        $this->assertTrue($supervisor->is_active);
        $this->assertDatabaseHas('company_supervisors', [
            'company_id' => $company->id,
            'user_id' => $supervisor->id,
            'position' => 'Manager',
        ]);
    }

    public function test_detach_supervisor(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $company = Company::create(['name' => 'Fresh Co', 'address' => 'A', 'is_active' => true]);
        $supervisor = User::factory()->create(['role' => 'supervisor']);
        $link = CompanySupervisor::create(['company_id' => $company->id, 'user_id' => $supervisor->id, 'position' => 'X']);

        Sanctum::actingAs($coordinator, ['*']);

        $this->deleteJson("/api/coordinator/companies/{$company->id}/supervisors/{$link->id}")->assertOk();

        $this->assertDatabaseMissing('company_supervisors', [
            'company_id' => $company->id,
            'user_id' => $supervisor->id,
        ]);
    }

    public function test_add_representative_creates_a_named_only_entry(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $company = Company::create(['name' => 'Fresh Co', 'address' => 'A', 'is_active' => true]);

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->postJson("/api/coordinator/companies/{$company->id}/representatives", [
            'name' => 'Juan Dela Cruz',
            'position' => 'HR Officer',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('company_supervisors', [
            'company_id' => $company->id,
            'user_id' => null,
            'name' => 'Juan Dela Cruz',
            'position' => 'HR Officer',
        ]);
    }

    public function test_add_representative_requires_name_and_position(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $company = Company::create(['name' => 'Fresh Co', 'address' => 'A', 'is_active' => true]);

        Sanctum::actingAs($coordinator, ['*']);

        $this->postJson("/api/coordinator/companies/{$company->id}/representatives", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'position']);
    }

    public function test_add_representative_is_blocked_for_an_out_of_scope_company(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $batch = $this->batchFor($bsit, $coordinator);
        $this->usedCompany($batch, 'InScope Co');

        $bsba = $this->programFor('BSBA-FM', 'CABM-B');
        $otherCoord = $this->coordinatorFor($bsba);
        $otherBatch = $this->batchFor($bsba, $otherCoord);
        $outScope = $this->usedCompany($otherBatch, 'OutScope Co');

        Sanctum::actingAs($coordinator, ['*']);

        $this->postJson("/api/coordinator/companies/{$outScope->id}/representatives", [
            'name' => 'Someone',
            'position' => 'Someone Else',
        ])->assertStatus(403);
    }

    public function test_representative_can_be_removed_via_the_existing_detach_endpoint(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $company = Company::create(['name' => 'Fresh Co', 'address' => 'A', 'is_active' => true]);
        $rep = CompanySupervisor::create(['company_id' => $company->id, 'name' => 'Rep One', 'position' => 'Clerk']);

        Sanctum::actingAs($coordinator, ['*']);

        $this->deleteJson("/api/coordinator/companies/{$company->id}/supervisors/{$rep->id}")->assertOk();
        $this->assertDatabaseMissing('company_supervisors', ['id' => $rep->id]);
    }

    public function test_head_of_company_contact_fields_round_trip_through_store_and_update(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);

        Sanctum::actingAs($coordinator, ['*']);

        $created = $this->postJson('/api/coordinator/companies', [
            'name' => 'Head Contact Co',
            'address' => '123 Street',
            'head_name' => 'Dr. Jane Santos',
            'head_contact_number' => '09171234567',
            'head_email' => 'jane.santos@example.com',
        ])->assertCreated()->json();

        $this->assertSame('09171234567', $created['head_contact_number']);
        $this->assertSame('jane.santos@example.com', $created['head_email']);

        $updated = $this->putJson("/api/coordinator/companies/{$created['id']}", [
            'head_contact_number' => '09179876543',
        ])->assertOk()->json();

        $this->assertSame('09179876543', $updated['head_contact_number']);
        $this->assertSame('jane.santos@example.com', $updated['head_email']);
    }

    public function test_detach_supervisor_by_id_removes_a_named_only_entry(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $company = Company::create(['name' => 'Fresh Co', 'address' => 'A', 'is_active' => true]);
        $namedOnly = CompanySupervisor::create(['company_id' => $company->id, 'name' => 'Juan Dela Cruz', 'position' => 'Office Head']);

        Sanctum::actingAs($coordinator, ['*']);

        $this->deleteJson("/api/coordinator/companies/{$company->id}/supervisors/{$namedOnly->id}")->assertOk();

        $this->assertDatabaseMissing('company_supervisors', ['id' => $namedOnly->id]);
    }

    public function test_attach_supervisor_blocked_when_company_already_has_a_login(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $company = Company::create(['name' => 'Fresh Co', 'address' => 'A', 'is_active' => true]);
        $existing = User::factory()->create(['role' => 'supervisor']);
        CompanySupervisor::create(['company_id' => $company->id, 'user_id' => $existing->id, 'position' => 'X']);
        $other = User::factory()->create(['role' => 'supervisor']);

        Sanctum::actingAs($coordinator, ['*']);

        $this->postJson("/api/coordinator/companies/{$company->id}/supervisors", [
            'user_id' => $other->id,
            'position' => 'Team Lead',
        ])->assertStatus(422);

        // Re-attaching the SAME login user stays idempotent, not blocked.
        $this->postJson("/api/coordinator/companies/{$company->id}/supervisors", [
            'user_id' => $existing->id,
            'position' => 'X',
        ])->assertOk();
    }

    public function test_create_supervisor_blocked_when_company_already_has_a_login(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $company = Company::create(['name' => 'Fresh Co', 'address' => 'A', 'is_active' => true]);
        $existing = User::factory()->create(['role' => 'supervisor']);
        CompanySupervisor::create(['company_id' => $company->id, 'user_id' => $existing->id, 'position' => 'X']);

        Sanctum::actingAs($coordinator, ['*']);

        $this->postJson("/api/coordinator/companies/{$company->id}/supervisors/new", [
            'name' => 'Another Supervisor',
            'email' => 'another@example.com',
            'password' => 'password123',
            'position' => 'Manager',
        ])->assertStatus(422);

        $this->assertDatabaseMissing('users', ['email' => 'another@example.com']);
    }

    public function test_attaching_a_new_login_re_syncs_active_enrollments_but_leaves_history(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $batch = $this->batchFor($bsit, $coordinator);
        $company = Company::create(['name' => 'Swap Co', 'address' => 'A', 'is_active' => true]);

        $oldLogin = User::factory()->create(['role' => 'supervisor']);
        $oldLink = CompanySupervisor::create(['company_id' => $company->id, 'user_id' => $oldLogin->id, 'position' => 'X']);
        $namedIndividual = CompanySupervisor::create(['company_id' => $company->id, 'name' => 'Ms. On-Site Lead', 'position' => 'Team Lead']);

        $activeStudent = User::factory()->create(['role' => 'student']);
        $completedStudent = User::factory()->create(['role' => 'student']);

        $activeRow = BatchStudent::create([
            'batch_id' => $batch->id,
            'student_id' => $activeStudent->id,
            'company_id' => $company->id,
            'supervisor_id' => $oldLogin->id,
            'company_supervisor_id' => $namedIndividual->id,
            'status' => 'active',
        ]);
        $completedRow = BatchStudent::create([
            'batch_id' => $batch->id,
            'student_id' => $completedStudent->id,
            'company_id' => $company->id,
            'supervisor_id' => $oldLogin->id,
            'status' => 'completed',
        ]);

        // Replace the login: detach the old, attach a new one.
        $newLogin = User::factory()->create(['role' => 'supervisor']);

        Sanctum::actingAs($coordinator, ['*']);

        $this->deleteJson("/api/coordinator/companies/{$company->id}/supervisors/{$oldLink->id}")->assertOk();
        $this->postJson("/api/coordinator/companies/{$company->id}/supervisors", [
            'user_id' => $newLogin->id,
            'position' => 'Ops Lead',
        ])->assertOk();

        // Active enrollment re-pointed at the new login; the named individual
        // (company_supervisor_id) is untouched.
        $this->assertDatabaseHas('batch_students', [
            'id' => $activeRow->id,
            'supervisor_id' => $newLogin->id,
            'company_supervisor_id' => $namedIndividual->id,
        ]);

        // Completed history keeps the supervisor who oversaw it.
        $this->assertDatabaseHas('batch_students', [
            'id' => $completedRow->id,
            'supervisor_id' => $oldLogin->id,
        ]);
    }
}
