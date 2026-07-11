<?php

namespace Tests\Feature\Admin;

use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\Company;
use App\Models\Department;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DepartmentControllerTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function enrollStudent(Program $program, string $status = 'active'): void
    {
        $company = Company::create(['name' => 'Partner '.uniqid(), 'address' => '123 Main St', 'is_active' => true]);
        $coordinator = User::factory()->create(['role' => 'coordinator']);
        $student = User::factory()->create(['role' => 'student', 'program_id' => $program->id]);
        $supervisor = User::factory()->create(['role' => 'supervisor']);

        $batch = Batch::create([
            'program_id' => $program->id,
            'coordinator_id' => $coordinator->id,
            'name' => 'Batch '.uniqid(),
            'start_date' => now()->subMonth(),
            'end_date' => now()->addMonth(),
            'required_hours' => 486,
            'working_days_per_week' => 5,
            'daily_reminder_time' => '21:00:00',
            'academic_year' => now()->format('Y'),
            'semester' => 'Internship',
            'is_active' => true,
        ]);

        BatchStudent::create([
            'batch_id' => $batch->id,
            'student_id' => $student->id,
            'company_id' => $company->id,
            'supervisor_id' => $supervisor->id,
            'status' => $status,
        ]);
    }

    public function test_index_returns_departments_with_program_counts(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $department = Department::create(['code' => 'CAST', 'name' => 'College of Arts, Sciences and Technology', 'is_active' => true]);
        Program::create(['department_id' => $department->id, 'code' => 'BSIT', 'name' => 'BS Information Technology', 'is_active' => true]);

        $response = $this->getJson('/api/admin/departments');

        $response->assertOk();
        $cast = collect($response->json())->firstWhere('code', 'CAST');
        $this->assertNotNull($cast);
        $this->assertSame(1, $cast['programs_count']);
    }

    public function test_show_returns_programs_with_intern_counts_and_department_total(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $department = Department::create(['code' => 'CABM-B', 'name' => 'College of Business Management', 'is_active' => true]);
        $bsba = Program::create(['department_id' => $department->id, 'code' => 'BSBA-FM', 'name' => 'BSBA Financial Management', 'is_active' => true]);
        $bsa = Program::create(['department_id' => $department->id, 'code' => 'BSA', 'name' => 'BS Accountancy', 'is_active' => true]);

        $this->enrollStudent($bsba, 'active');
        $this->enrollStudent($bsba, 'completed');
        $this->enrollStudent($bsa, 'active');

        $response = $this->getJson("/api/admin/departments/{$department->id}");

        $response->assertOk();
        $response->assertJsonCount(2, 'programs');
        $this->assertSame(2, $response->json('active_interns_count'));

        $programs = collect($response->json('programs'))->keyBy('code');
        $this->assertSame(1, $programs['BSBA-FM']['active_interns_count']);
        $this->assertSame(2, $programs['BSBA-FM']['total_interns_count']);
        $this->assertSame(1, $programs['BSA']['active_interns_count']);
        $this->assertSame(1, $programs['BSA']['total_interns_count']);

        $this->assertCount(3, $response->json('students'));
        $studentPrograms = collect($response->json('students'))->pluck('program.name');
        $this->assertTrue($studentPrograms->contains('BSBA Financial Management'));
        $this->assertTrue($studentPrograms->contains('BS Accountancy'));

        $this->assertCount(3, $response->json('companies'));
    }

    public function test_show_handles_a_department_with_no_programs(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $department = Department::create(['code' => 'CABM-H', 'name' => 'College of Hospitality Management', 'is_active' => true]);

        $response = $this->getJson("/api/admin/departments/{$department->id}");

        $response->assertOk();
        $response->assertJsonCount(0, 'programs');
        $this->assertSame(0, $response->json('active_interns_count'));
        $response->assertJsonCount(0, 'students');
        $response->assertJsonCount(0, 'companies');
    }

    public function test_non_admin_cannot_access_department_show(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'student']), ['*']);

        $department = Department::create(['code' => 'CAST', 'name' => 'College of Arts, Sciences and Technology', 'is_active' => true]);

        $this->getJson("/api/admin/departments/{$department->id}")->assertStatus(403);
    }

    public function test_admin_assigns_a_coordinator_and_it_appears_in_show(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $department = Department::create(['code' => 'CAST', 'name' => 'College of Arts, Sciences and Technology', 'is_active' => true]);
        $coordinator = User::factory()->create(['role' => 'coordinator', 'name' => 'Coord One']);

        $response = $this->postJson("/api/admin/departments/{$department->id}/coordinators", [
            'user_id' => $coordinator->id,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('coordinator_departments', [
            'coordinator_id' => $coordinator->id,
            'department_id' => $department->id,
        ]);

        $show = $this->getJson("/api/admin/departments/{$department->id}");
        $show->assertOk();
        $names = collect($show->json('coordinators'))->pluck('name');
        $this->assertTrue($names->contains('Coord One'));
    }

    public function test_assigning_a_non_coordinator_is_rejected(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $department = Department::create(['code' => 'CAST', 'name' => 'College of Arts, Sciences and Technology', 'is_active' => true]);
        $student = User::factory()->create(['role' => 'student']);

        $response = $this->postJson("/api/admin/departments/{$department->id}/coordinators", [
            'user_id' => $student->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['user_id']);
        $this->assertDatabaseMissing('coordinator_departments', [
            'coordinator_id' => $student->id,
            'department_id' => $department->id,
        ]);
    }

    public function test_duplicate_coordinator_assignment_does_not_create_a_duplicate_row(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $department = Department::create(['code' => 'CAST', 'name' => 'College of Arts, Sciences and Technology', 'is_active' => true]);
        $coordinator = User::factory()->create(['role' => 'coordinator']);

        $this->postJson("/api/admin/departments/{$department->id}/coordinators", ['user_id' => $coordinator->id])
            ->assertCreated();
        $this->postJson("/api/admin/departments/{$department->id}/coordinators", ['user_id' => $coordinator->id])
            ->assertCreated();

        $this->assertSame(1, DB::table('coordinator_departments')
            ->where('coordinator_id', $coordinator->id)
            ->where('department_id', $department->id)
            ->count());
    }

    public function test_store_creates_a_department_via_the_form_request(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $response = $this->postJson('/api/admin/departments', [
            'code' => 'CABM-H',
            'name' => 'College of Hospitality Management',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('departments', ['code' => 'CABM-H', 'is_active' => true]);
    }

    public function test_store_requires_a_unique_code(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        Department::create(['code' => 'CAST', 'name' => 'College of Arts, Sciences and Technology', 'is_active' => true]);

        $response = $this->postJson('/api/admin/departments', [
            'code' => 'CAST',
            'name' => 'Duplicate Department',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['code']);
    }

    public function test_update_can_toggle_is_active(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $department = Department::create(['code' => 'CAST', 'name' => 'College of Arts, Sciences and Technology', 'is_active' => true]);

        $response = $this->putJson("/api/admin/departments/{$department->id}", [
            'name' => 'College of Arts, Sciences and Technology',
            'is_active' => false,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('departments', ['id' => $department->id, 'is_active' => false]);
    }

    public function test_admin_removes_an_assigned_coordinator(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $department = Department::create(['code' => 'CAST', 'name' => 'College of Arts, Sciences and Technology', 'is_active' => true]);
        $coordinator = User::factory()->create(['role' => 'coordinator']);
        $coordinator->departmentsCoordinated()->attach($department->id);

        $response = $this->deleteJson("/api/admin/departments/{$department->id}/coordinators/{$coordinator->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('coordinator_departments', [
            'coordinator_id' => $coordinator->id,
            'department_id' => $department->id,
        ]);
    }
}
