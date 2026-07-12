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

    public function test_index_returns_programs_with_department(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $department = $this->department();
        Program::create(['department_id' => $department->id, 'code' => 'BSIT', 'name' => 'BS Information Technology', 'is_active' => true]);

        $response = $this->getJson('/api/admin/programs');

        $response->assertOk();
        $this->assertSame('CAST', collect($response->json())->firstWhere('code', 'BSIT')['department']['code']);
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

    public function test_store_and_update_routes_no_longer_exist(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $department = $this->department();
        $program = Program::create(['department_id' => $department->id, 'code' => 'BSIT', 'name' => 'BS Information Technology', 'is_active' => true]);

        $this->postJson('/api/admin/programs', ['department_id' => $department->id, 'code' => 'z', 'name' => 'z'])
            ->assertStatus(405);
        $this->putJson("/api/admin/programs/{$program->id}", ['name' => 'x', 'code' => 'y'])
            ->assertStatus(405);
    }

    public function test_non_admin_cannot_access_program_routes(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'coordinator']), ['*']);

        $department = $this->department();
        $program = Program::create(['department_id' => $department->id, 'code' => 'BSIT', 'name' => 'BS Information Technology', 'is_active' => true]);

        $this->getJson('/api/admin/programs')->assertStatus(403);
        $this->getJson("/api/admin/programs/{$program->id}")->assertStatus(403);
    }
}
