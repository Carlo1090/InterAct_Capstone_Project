<?php

namespace Tests\Feature\Student;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PasswordControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_wrong_current_password_is_rejected(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        Sanctum::actingAs($student, ['*']);

        $response = $this->putJson('/api/student/password', [
            'current_password' => 'wrong-password',
            'password' => 'a-new-password-123',
            'password_confirmation' => 'a-new-password-123',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['current_password']);
    }

    public function test_student_can_change_their_password_and_the_flag_clears(): void
    {
        $student = User::factory()->create(['role' => 'student', 'must_change_password' => true]);
        Sanctum::actingAs($student, ['*']);

        $response = $this->putJson('/api/student/password', [
            'current_password' => 'password',
            'password' => 'a-new-password-123',
            'password_confirmation' => 'a-new-password-123',
        ]);

        $response->assertOk();

        $student->refresh();
        $this->assertFalse($student->must_change_password);
        $this->assertTrue(Hash::check('a-new-password-123', $student->password));
    }

    public function test_must_change_password_flag_is_reflected_on_the_user_endpoint(): void
    {
        $student = User::factory()->create(['role' => 'student', 'must_change_password' => true]);
        Sanctum::actingAs($student, ['*']);

        $response = $this->getJson('/api/user');

        $response->assertOk();
        $this->assertTrue($response->json('must_change_password'));
    }

    public function test_new_password_must_differ_from_current(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        Sanctum::actingAs($student, ['*']);

        $response = $this->putJson('/api/student/password', [
            'current_password' => 'password',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['password']);
    }
}
