<?php

namespace Tests\Feature\Admin;

use App\Models\Department;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProgramControllerTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function department(): Department
    {
        return Department::create(['code' => 'CAST', 'name' => 'College of Arts, Sciences and Technology', 'is_active' => true]);
    }

    public function test_store_creates_a_program_via_the_form_request(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $department = $this->department();

        $response = $this->postJson('/api/admin/programs', [
            'department_id' => $department->id,
            'code' => 'BSIT',
            'name' => 'BS Information Technology',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('programs', ['code' => 'BSIT', 'department_id' => $department->id, 'is_active' => true]);
    }

    public function test_store_requires_a_unique_code_within_department(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $department = $this->department();
        Program::create(['department_id' => $department->id, 'code' => 'BSIT', 'name' => 'BS Information Technology', 'is_active' => true]);

        $response = $this->postJson('/api/admin/programs', [
            'department_id' => $department->id,
            'code' => 'BSIT',
            'name' => 'Duplicate Program',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['code']);
    }

    public function test_show_returns_the_program_with_its_department(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $department = $this->department();
        $program = Program::create(['department_id' => $department->id, 'code' => 'BSIT', 'name' => 'BS Information Technology', 'is_active' => true]);

        $response = $this->getJson("/api/admin/programs/{$program->id}");

        $response->assertOk();
        $this->assertSame('CAST', $response->json('department.code'));
    }

    public function test_update_changes_name_code_and_is_active(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $department = $this->department();
        $program = Program::create(['department_id' => $department->id, 'code' => 'BSIT', 'name' => 'BS Information Technology', 'is_active' => true]);

        $response = $this->putJson("/api/admin/programs/{$program->id}", [
            'name' => 'BS Information Technology (Updated)',
            'code' => 'BSIT-2',
            'is_active' => false,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('programs', [
            'id' => $program->id,
            'name' => 'BS Information Technology (Updated)',
            'code' => 'BSIT-2',
            'is_active' => false,
        ]);
    }

    public function test_update_allows_keeping_the_same_code(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $department = $this->department();
        $program = Program::create(['department_id' => $department->id, 'code' => 'BSIT', 'name' => 'BS Information Technology', 'is_active' => true]);

        $response = $this->putJson("/api/admin/programs/{$program->id}", [
            'name' => 'BS Information Technology',
            'code' => 'BSIT',
        ]);

        $response->assertOk();
    }

    public function test_update_rejects_a_code_already_used_by_another_program_in_the_same_department(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $department = $this->department();
        Program::create(['department_id' => $department->id, 'code' => 'BSIT', 'name' => 'BS Information Technology', 'is_active' => true]);
        $program = Program::create(['department_id' => $department->id, 'code' => 'BSA', 'name' => 'BS Accountancy', 'is_active' => true]);

        $response = $this->putJson("/api/admin/programs/{$program->id}", [
            'name' => 'BS Accountancy',
            'code' => 'BSIT',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['code']);
    }

    public function test_non_admin_cannot_access_program_routes(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'coordinator']), ['*']);

        $department = $this->department();
        $program = Program::create(['department_id' => $department->id, 'code' => 'BSIT', 'name' => 'BS Information Technology', 'is_active' => true]);

        $this->getJson("/api/admin/programs/{$program->id}")->assertStatus(403);
        $this->putJson("/api/admin/programs/{$program->id}", ['name' => 'x', 'code' => 'y'])->assertStatus(403);
        $this->postJson('/api/admin/programs', ['department_id' => $department->id, 'code' => 'z', 'name' => 'z'])->assertStatus(403);
    }
}
