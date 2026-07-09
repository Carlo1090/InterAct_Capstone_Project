<?php

namespace Tests\Feature\Admin;

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
}
