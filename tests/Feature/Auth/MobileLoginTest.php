<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Bearer-token auth for the mobile app (POST /api/mobile/login,
 * POST /api/mobile/logout), distinct from the web SPA's session-cookie
 * login. Mobile is student-only scope — every other role must be rejected
 * even with correct credentials.
 */
class MobileLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('student|127.0.0.1');
        RateLimiter::clear('mdcstudent|127.0.0.1');
    }

    public function test_a_student_can_log_in_and_receives_a_token(): void
    {
        User::factory()->create([
            'username' => 'mdcstudent',
            'password' => 'password',
            'role' => 'student',
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/mobile/login', [
            'login' => 'mdcstudent',
            'password' => 'password',
        ]);

        $response->assertSuccessful();
        $response->assertJsonStructure(['token', 'user' => ['id', 'name', 'username', 'role']]);
        $this->assertNotEmpty($response->json('token'));
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_a_wrong_password_is_rejected_with_a_real_error(): void
    {
        User::factory()->create([
            'username' => 'mdcstudent',
            'password' => 'password',
            'role' => 'student',
        ]);

        $response = $this->postJson('/api/mobile/login', [
            'login' => 'mdcstudent',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('login');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_a_deactivated_account_is_rejected(): void
    {
        User::factory()->create([
            'username' => 'mdcstudent',
            'password' => 'password',
            'role' => 'student',
            'is_active' => false,
        ]);

        $response = $this->postJson('/api/mobile/login', [
            'login' => 'mdcstudent',
            'password' => 'password',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('login');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_a_non_student_role_is_rejected_even_with_correct_credentials(): void
    {
        User::factory()->create([
            'username' => 'mdccoordinator',
            'password' => 'password',
            'role' => 'coordinator',
        ]);

        $response = $this->postJson('/api/mobile/login', [
            'login' => 'mdccoordinator',
            'password' => 'password',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('login');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_it_accepts_login_by_email_too(): void
    {
        User::factory()->create([
            'username' => 'mdcstudent',
            'email' => 'mdcstudent@example.com',
            'password' => 'password',
            'role' => 'student',
        ]);

        $this->postJson('/api/mobile/login', [
            'login' => 'mdcstudent@example.com',
            'password' => 'password',
        ])->assertSuccessful();
    }

    public function test_it_locks_out_after_five_failed_attempts(): void
    {
        User::factory()->create([
            'username' => 'mdcstudent',
            'password' => 'password',
            'role' => 'student',
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/mobile/login', [
                'login' => 'mdcstudent',
                'password' => 'wrong-password',
            ])->assertStatus(422);
        }

        $response = $this->postJson('/api/mobile/login', [
            'login' => 'mdcstudent',
            'password' => 'password',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('login');
    }

    public function test_logout_revokes_the_token(): void
    {
        $user = User::factory()->create([
            'username' => 'mdcstudent',
            'password' => 'password',
            'role' => 'student',
        ]);

        $token = $user->createToken('mobile-app', ['*'])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/mobile/logout')
            ->assertSuccessful();

        $this->assertDatabaseCount('personal_access_tokens', 0);

        // Sanctum's RequestGuard caches its resolved user for the lifetime of
        // the guard instance, which — unlike production, where each request
        // boots a fresh app — persists across multiple test HTTP calls within
        // one test method. Without this, the earlier logout call's resolved
        // user would still be cached and this assertion would pass for the
        // wrong reason.
        Auth::forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/user')
            ->assertUnauthorized();
    }
}
