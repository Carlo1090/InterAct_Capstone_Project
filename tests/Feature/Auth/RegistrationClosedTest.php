<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Accounts in InternTrack are staff-provisioned only — an admin creates
 * coordinators, a coordinator creates students and supervisors. Breeze's
 * self-service registration route shipped by default and created an ACTIVE
 * `role: student` account for any anonymous caller, with no throttle. It was
 * removed rather than gated; this test is what keeps it removed, since a future
 * `php artisan breeze:install` or a copied-in auth stub would silently put it
 * back.
 */
class RegistrationClosedTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_self_service_registration_endpoint_does_not_exist(): void
    {
        $response = $this->postJson('/register', [
            'name' => 'Walk In',
            'email' => 'walkin@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertNotFound();
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'walkin@example.com']);
    }

    public function test_no_route_is_named_register(): void
    {
        $this->assertFalse(
            app('router')->has('register'),
            'A route named `register` is back. Accounts must stay staff-provisioned.'
        );
    }

    public function test_logging_in_still_works_so_the_removal_did_not_break_auth(): void
    {
        $user = User::factory()->create([
            'username' => 'staffmade',
            'password' => 'password',
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->postJson('/login', [
            'login' => 'staffmade',
            'password' => 'password',
        ])->assertSuccessful();

        $this->assertAuthenticatedAs($user);
    }
}
