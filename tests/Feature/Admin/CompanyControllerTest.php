<?php

namespace Tests\Feature\Admin;

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

class CompanyControllerTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function placeStudentAt(Company $company, Department $department): void
    {
        $program = Program::create([
            'department_id' => $department->id,
            'code' => 'PRG-'.uniqid(),
            'name' => 'Test Program',
            'is_active' => true,
        ]);

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
            'status' => 'active',
        ]);
    }

    public function test_index_returns_companies_with_intern_counts(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $department = Department::create(['code' => 'CAST', 'name' => 'College of Arts, Sciences and Technology', 'is_active' => true]);
        $company = Company::create(['name' => 'Acme Corp', 'address' => '123 Main St', 'is_active' => true]);
        $this->placeStudentAt($company, $department);

        $response = $this->getJson('/api/admin/companies');

        $response->assertOk();
        $acme = collect($response->json('data'))->firstWhere('name', 'Acme Corp');
        $this->assertNotNull($acme);
        $this->assertSame(1, $acme['active_interns_count']);
        $this->assertSame(1, $acme['total_interns_count']);
    }

    public function test_department_filter_only_returns_companies_with_a_matching_placement(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $castDepartment = Department::create(['code' => 'CAST', 'name' => 'College of Arts, Sciences and Technology', 'is_active' => true]);
        $cabmDepartment = Department::create(['code' => 'CABM-B', 'name' => 'College of Business Management', 'is_active' => true]);

        $castCompany = Company::create(['name' => 'CAST Partner Co', 'address' => 'Address A', 'is_active' => true]);
        $cabmCompany = Company::create(['name' => 'CABM Partner Co', 'address' => 'Address B', 'is_active' => true]);

        $this->placeStudentAt($castCompany, $castDepartment);
        $this->placeStudentAt($cabmCompany, $cabmDepartment);

        $response = $this->getJson('/api/admin/companies?department_id='.$castDepartment->id);

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('CAST Partner Co'));
        $this->assertFalse($names->contains('CABM Partner Co'));
    }

    public function test_show_returns_supervisors_and_derived_departments(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $department = Department::create(['code' => 'CAST', 'name' => 'College of Arts, Sciences and Technology', 'is_active' => true]);
        $company = Company::create(['name' => 'Acme Corp', 'address' => '123 Main St', 'is_active' => true]);
        $this->placeStudentAt($company, $department);

        $supervisorUser = User::factory()->create(['role' => 'supervisor', 'name' => 'Jane Supervisor']);
        CompanySupervisor::create(['company_id' => $company->id, 'user_id' => $supervisorUser->id, 'position' => 'HR Manager']);

        $response = $this->getJson("/api/admin/companies/{$company->id}");

        $response->assertOk();
        $this->assertCount(1, $response->json('supervisors'));
        $this->assertSame('Jane Supervisor', $response->json('supervisors.0.user.name'));
        $this->assertCount(1, $response->json('departments'));
        $this->assertSame('CAST', $response->json('departments.0.code'));
    }

    public function test_store_creates_a_company(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $response = $this->postJson('/api/admin/companies', [
            'name' => 'New Partner Inc.',
            'address' => '456 Side St',
            'location' => 'Cebu City',
            'industry' => 'Retail',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('companies', ['name' => 'New Partner Inc.', 'is_active' => true]);
    }

    public function test_store_requires_name_and_address(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $response = $this->postJson('/api/admin/companies', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name', 'address']);
    }

    public function test_update_validates_and_updates_a_company(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $company = Company::create(['name' => 'Old Name', 'address' => 'Old Address', 'is_active' => true]);

        $response = $this->putJson("/api/admin/companies/{$company->id}", [
            'name' => 'Updated Name',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('companies', ['id' => $company->id, 'name' => 'Updated Name']);
    }

    public function test_non_admin_cannot_access_companies(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'student']), ['*']);

        $this->getJson('/api/admin/companies')->assertStatus(403);
    }
}
