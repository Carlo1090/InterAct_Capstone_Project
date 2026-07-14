<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UsernameAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_username_only_student_with_no_email_can_log_in_by_username(): void
    {
        User::factory()->create([
            'username' => 'juan.delacruz',
            'email' => null,
            'password' => Hash::make('secret-pass'),
            'role' => 'student',
        ]);

        $response = $this->post('/login', [
            'login' => 'juan.delacruz',
            'password' => 'secret-pass',
        ]);

        $this->assertAuthenticated();
        $response->assertOk()->assertJsonStructure(['user']);
    }

    public function test_existing_email_account_can_still_log_in_by_email(): void
    {
        $user = User::factory()->create([
            'email' => 'legacy@interntrack.local',
            'password' => Hash::make('password'),
        ]);

        $response = $this->post('/login', [
            'login' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertOk()->assertJsonStructure(['user']);
    }

    public function test_wrong_password_by_username_does_not_authenticate(): void
    {
        User::factory()->create([
            'username' => 'maria.santos',
            'email' => null,
            'password' => Hash::make('correct-pass'),
        ]);

        $this->post('/login', [
            'login' => 'maria.santos',
            'password' => 'wrong-pass',
        ]);

        $this->assertGuest();
    }

    public function test_username_uniqueness_is_enforced(): void
    {
        User::factory()->create(['username' => 'dupe.user']);

        $this->expectException(QueryException::class);

        User::factory()->create(['username' => 'dupe.user']);
    }

    public function test_username_is_auto_generated_when_not_supplied(): void
    {
        $a = User::create([
            'name' => 'Auto One',
            'email' => 'auto.one@example.com',
            'password' => Hash::make('password'),
            'role' => 'student',
        ]);

        $b = User::create([
            'name' => 'Auto Two',
            'email' => 'auto.one@somewhere-else.com',
            'password' => Hash::make('password'),
            'role' => 'student',
        ]);

        $this->assertSame('auto.one', $a->username);
        // Same local-part collides, so the second gets a numeric suffix.
        $this->assertSame('auto.one1', $b->username);
    }
}
