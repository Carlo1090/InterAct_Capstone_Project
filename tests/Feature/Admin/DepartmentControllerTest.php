<?php

namespace Tests\Feature\Admin;

use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\Company;
use App\Models\Department;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    }

    public function test_show_handles_a_department_with_no_programs(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $department = Department::create(['code' => 'CABM-H', 'name' => 'College of Hospitality Management', 'is_active' => true]);

        $response = $this->getJson("/api/admin/departments/{$department->id}");

        $response->assertOk();
        $response->assertJsonCount(0, 'programs');
        $this->assertSame(0, $response->json('active_interns_count'));
    }

    public function test_non_admin_cannot_access_department_show(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'student']), ['*']);

        $department = Department::create(['code' => 'CAST', 'name' => 'College of Arts, Sciences and Technology', 'is_active' => true]);

        $this->getJson("/api/admin/departments/{$department->id}")->assertStatus(403);
    }
}
