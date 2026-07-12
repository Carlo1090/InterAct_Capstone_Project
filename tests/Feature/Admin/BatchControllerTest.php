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

class BatchControllerTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function makeBatch(?Department $department = null): Batch
    {
        $department ??= Department::create(['code' => 'CAST', 'name' => 'College of Arts, Sciences and Technology', 'is_active' => true]);
        $program = Program::create(['department_id' => $department->id, 'code' => 'BSIT-'.uniqid(), 'name' => 'BS Information Technology', 'is_active' => true]);
        $coordinator = User::factory()->create(['role' => 'coordinator']);

        return Batch::create([
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
    }

    public function test_show_returns_program_department_coordinator_and_roster(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $batch = $this->makeBatch();
        $company = Company::create(['name' => 'Acme Corp', 'address' => '123 Main St', 'is_active' => true]);
        $student = User::factory()->create(['role' => 'student', 'program_id' => $batch->program_id, 'name' => 'Jane Student']);
        $supervisor = User::factory()->create(['role' => 'supervisor', 'name' => 'Sam Supervisor']);

        BatchStudent::create([
            'batch_id' => $batch->id,
            'student_id' => $student->id,
            'company_id' => $company->id,
            'supervisor_id' => $supervisor->id,
            'status' => 'active',
        ]);

        $response = $this->getJson("/api/admin/batches/{$batch->id}");

        $response->assertOk();
        $this->assertSame('CAST', $response->json('program.department.code'));
        $this->assertCount(1, $response->json('batch_students'));
        $this->assertSame('Jane Student', $response->json('batch_students.0.student.name'));
        $this->assertSame('Acme Corp', $response->json('batch_students.0.company.name'));
        $this->assertSame('Sam Supervisor', $response->json('batch_students.0.supervisor.name'));
        $this->assertSame('active', $response->json('batch_students.0.status'));
    }

    public function test_show_handles_a_batch_with_no_enrolled_students(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $batch = $this->makeBatch();

        $response = $this->getJson("/api/admin/batches/{$batch->id}");

        $response->assertOk();
        $response->assertJsonCount(0, 'batch_students');
    }

    public function test_department_filter_only_returns_batches_in_that_department(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $castDepartment = Department::create(['code' => 'CAST', 'name' => 'College of Arts, Sciences and Technology', 'is_active' => true]);
        $cabmDepartment = Department::create(['code' => 'CABM-B', 'name' => 'College of Business Management', 'is_active' => true]);

        $castBatch = $this->makeBatch($castDepartment);
        $cabmBatch = $this->makeBatch($cabmDepartment);

        $response = $this->getJson("/api/admin/batches?department_id={$castDepartment->id}");

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains($castBatch->name));
        $this->assertFalse($names->contains($cabmBatch->name));
    }

    public function test_store_and_update_routes_no_longer_exist(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $batch = $this->makeBatch();

        $this->postJson('/api/admin/batches', ['name' => 'x'])->assertStatus(405);
        $this->putJson("/api/admin/batches/{$batch->id}", ['name' => 'x'])->assertStatus(405);
    }

    public function test_non_admin_cannot_access_batch_show(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'coordinator']), ['*']);

        $batch = $this->makeBatch();

        $this->getJson("/api/admin/batches/{$batch->id}")->assertStatus(403);
        $this->getJson('/api/admin/batches')->assertStatus(403);
    }
}
