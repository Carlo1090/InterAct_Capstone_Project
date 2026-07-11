<?php

namespace Tests\Feature\Admin;

use App\Models\Department;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserControllerTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_search_filters_users_by_name(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        User::factory()->create(['role' => 'student', 'name' => 'Juan Dela Cruz']);
        User::factory()->create(['role' => 'student', 'name' => 'Maria Santos']);

        $response = $this->getJson('/api/admin/users?search=Juan');

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Juan Dela Cruz'));
        $this->assertFalse($names->contains('Maria Santos'));
    }

    public function test_role_filter_only_returns_matching_role(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        User::factory()->create(['role' => 'student', 'name' => 'Student One']);
        User::factory()->create(['role' => 'coordinator', 'name' => 'Coordinator One']);

        $response = $this->getJson('/api/admin/users?role=coordinator');

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Coordinator One'));
        $this->assertFalse($names->contains('Student One'));
    }

    public function test_department_filter_only_returns_users_in_that_department(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $castDepartment = Department::create(['code' => 'CAST', 'name' => 'College of Arts, Sciences and Technology', 'is_active' => true]);
        $cabmDepartment = Department::create(['code' => 'CABM-B', 'name' => 'College of Business Management', 'is_active' => true]);

        $castProgram = Program::create(['department_id' => $castDepartment->id, 'code' => 'BSIT', 'name' => 'BS Information Technology', 'is_active' => true]);
        $cabmProgram = Program::create(['department_id' => $cabmDepartment->id, 'code' => 'BSA', 'name' => 'BS Accountancy', 'is_active' => true]);

        User::factory()->create(['role' => 'student', 'name' => 'CAST Student', 'program_id' => $castProgram->id]);
        User::factory()->create(['role' => 'student', 'name' => 'CABM Student', 'program_id' => $cabmProgram->id]);
        User::factory()->create(['role' => 'admin', 'name' => 'No Program Admin', 'program_id' => null]);

        $response = $this->getJson("/api/admin/users?department_id={$castDepartment->id}");

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('CAST Student'));
        $this->assertFalse($names->contains('CABM Student'));
        $this->assertFalse($names->contains('No Program Admin'));
    }

    public function test_admin_can_issue_a_temporary_password_for_a_student(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $student = User::factory()->create(['role' => 'student', 'email' => 'student@example.test']);

        $response = $this->patchJson("/api/admin/users/{$student->id}/temporary-password");

        $response->assertOk();
        $temporaryPassword = $response->json('temporary_password');
        $this->assertNotEmpty($temporaryPassword);

        $student->refresh();
        $this->assertTrue($student->must_change_password);
        $this->assertFalse(Hash::check('password', $student->password));
        $this->assertTrue(Hash::check($temporaryPassword, $student->password));
    }

    public function test_non_admin_cannot_issue_a_temporary_password(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'student']), ['*']);

        $target = User::factory()->create(['role' => 'student']);

        $this->patchJson("/api/admin/users/{$target->id}/temporary-password")->assertStatus(403);
    }

    public function test_admin_can_reactivate_a_deactivated_user(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $target = User::factory()->create(['role' => 'student', 'is_active' => false]);

        $response = $this->patchJson("/api/admin/users/{$target->id}/activate");

        $response->assertOk();
        $this->assertDatabaseHas('users', ['id' => $target->id, 'is_active' => true]);
    }

    public function test_non_admin_cannot_activate_a_user(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'student']), ['*']);

        $target = User::factory()->create(['role' => 'student', 'is_active' => false]);

        $this->patchJson("/api/admin/users/{$target->id}/activate")->assertStatus(403);
    }

    public function test_creating_a_coordinator_without_a_department_is_rejected(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $response = $this->postJson('/api/admin/users', [
            'name' => 'New Coordinator',
            'email' => 'new.coordinator@example.test',
            'password' => 'a-strong-password',
            'role' => 'coordinator',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['department_ids']);
    }

    public function test_creating_a_coordinator_with_departments_attaches_them(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $department = Department::create(['code' => 'CAST', 'name' => 'College of Arts, Sciences and Technology', 'is_active' => true]);

        $response = $this->postJson('/api/admin/users', [
            'name' => 'New Coordinator',
            'email' => 'new.coordinator@example.test',
            'password' => 'a-strong-password',
            'role' => 'coordinator',
            'department_ids' => [$department->id],
        ]);

        $response->assertCreated();
        $coordinator = User::where('email', 'new.coordinator@example.test')->firstOrFail();
        $this->assertTrue($coordinator->departmentsCoordinated()->where('departments.id', $department->id)->exists());
    }

    public function test_creating_a_second_admin_is_rejected(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $response = $this->postJson('/api/admin/users', [
            'name' => 'Second Admin',
            'email' => 'second.admin@example.test',
            'password' => 'a-strong-password',
            'role' => 'admin',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['role']);
    }
}
