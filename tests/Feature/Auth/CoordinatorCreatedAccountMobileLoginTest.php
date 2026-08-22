<?php

namespace Tests\Feature\Auth;

use App\Models\Batch;
use App\Models\Department;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * End-to-end proof that a REAL coordinator-created student account can sign
 * into the mobile app — not just the seeded demo accounts.
 *
 * This is the one thing MobileLoginTest cannot show: it builds its users with
 * a factory, so it proves the login endpoint works but says nothing about
 * whether the account intake flow actually produces a mobile-loginable user.
 * The concern is real because coordinator-created accounts differ from seeded
 * ones in ways that could plausibly break login — they are created NOT
 * ENROLLED, gated behind an unapproved info sheet, and may be given an
 * auto-generated username rather than a chosen one.
 *
 * The two halves are deliberately exercised through their real HTTP
 * endpoints rather than by constructing models, so the test would catch a
 * regression in either controller.
 */
class CoordinatorCreatedAccountMobileLoginTest extends TestCase
{
    use RefreshDatabase;

    private function scaffold(): array
    {
        $department = Department::create(['code' => 'CAST', 'name' => 'CAST', 'is_active' => true]);
        $program = Program::create([
            'department_id' => $department->id,
            'code' => 'BSIT',
            'name' => 'BS Information Technology',
            'is_active' => true,
        ]);

        $coordinator = User::factory()->create(['role' => 'coordinator']);
        $coordinator->departmentsCoordinated()->attach($department->id);

        $batch = Batch::create([
            'program_id' => $program->id,
            'coordinator_id' => $coordinator->id,
            'name' => 'BSIT 2026 Internship',
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonths(3)->toDateString(),
            'required_hours' => 486,
            'working_days_per_week' => 5,
            'academic_year' => now()->format('Y'),
            'semester' => 'Internship',
            'is_active' => true,
        ]);

        return [$coordinator, $program, $batch];
    }

    public function test_a_coordinator_created_student_can_log_into_the_mobile_app(): void
    {
        RateLimiter::clear('newintern|127.0.0.1');

        [$coordinator, $program, $batch] = $this->scaffold();

        Sanctum::actingAs($coordinator);

        $this->postJson('/api/coordinator/accounts', [
            'first_name' => 'Maria',
            'last_name' => 'Reyes',
            'username' => 'newintern',
            'password' => 'coordinator-set-password',
            'role' => 'student',
            'program_id' => $program->id,
            'batch_id' => $batch->id,
        ])->assertSuccessful();

        // Drop the coordinator's guard state — RequestGuard caches its
        // resolved user for the life of the instance, which in-process
        // persists across calls in a way production never does.
        Auth::forgetGuards();

        $response = $this->postJson('/api/mobile/login', [
            'login' => 'newintern',
            'password' => 'coordinator-set-password',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'role']])
            ->assertJsonPath('user.role', 'student')
            ->assertJsonPath('user.name', 'Maria Reyes')
            // Created NOT ENROLLED with only a draft info sheet, so the app
            // must land them on the Info Sheet rather than the dashboard.
            // A token is still issued — gating is a routing concern, not an
            // auth one.
            ->assertJsonPath('user.student_gated', true);

        $this->assertNotEmpty($response->json('token'));
    }

    public function test_a_coordinator_created_student_with_an_auto_generated_username_can_also_log_in(): void
    {
        [$coordinator, $program, $batch] = $this->scaffold();

        Sanctum::actingAs($coordinator);

        // Username is optional on the intake form — blank auto-generates one.
        // That generated value is what the student is told to log in with, so
        // it has to work on mobile exactly like a chosen one.
        $this->postJson('/api/coordinator/accounts', [
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'password' => 'coordinator-set-password',
            'role' => 'student',
            'program_id' => $program->id,
            'batch_id' => $batch->id,
        ])->assertSuccessful();

        $student = User::where('role', 'student')->firstOrFail();
        $this->assertNotEmpty($student->username);

        RateLimiter::clear($student->username.'|127.0.0.1');
        Auth::forgetGuards();

        $this->postJson('/api/mobile/login', [
            'login' => $student->username,
            'password' => 'coordinator-set-password',
        ])->assertOk()->assertJsonPath('user.role', 'student');
    }

    public function test_a_coordinator_created_SUPERVISOR_account_is_still_refused_by_the_mobile_app(): void
    {
        RateLimiter::clear('newsup|127.0.0.1');

        [$coordinator] = $this->scaffold();

        Sanctum::actingAs($coordinator);

        // Coordinators can create supervisors too — mobile is student-only,
        // so a correct supervisor password must still get no token.
        $this->postJson('/api/coordinator/accounts', [
            'first_name' => 'Ana',
            'last_name' => 'Santos',
            'username' => 'newsup',
            'password' => 'coordinator-set-password',
            'role' => 'supervisor',
        ])->assertSuccessful();

        Auth::forgetGuards();

        $this->postJson('/api/mobile/login', [
            'login' => 'newsup',
            'password' => 'coordinator-set-password',
        ])->assertStatus(422)->assertJsonMissingPath('token');
    }
}
