<?php

namespace Tests\Feature\Console;

use App\Models\Department;
use App\Models\Program;
use App\Models\User;
use Database\Seeders\ProductionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

/**
 * Covers the two pieces that exist purely to make a deployment safe:
 * ProductionSeeder (what a real deploy seeds instead of DatabaseSeeder's twelve
 * demo seeders) and `demo:set-password` (which rotates the shared demo password
 * this public repo documents).
 */
class DeploymentSeedingTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $touchedEnvKeys = [];

    protected function tearDown(): void
    {
        foreach ($this->touchedEnvKeys as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }

        $this->touchedEnvKeys = [];

        parent::tearDown();
    }

    private function setEnv(string $key, string $value): void
    {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        $this->touchedEnvKeys[] = $key;
    }

    public function test_production_seeder_refuses_to_run_without_admin_credentials(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ADMIN_USERNAME and ADMIN_PASSWORD');

        $this->seed(ProductionSeeder::class);
    }

    public function test_production_seeder_rejects_a_short_admin_password(): void
    {
        $this->setEnv('ADMIN_USERNAME', 'realadmin');
        $this->setEnv('ADMIN_PASSWORD', 'short');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('at least 12 characters');

        $this->seed(ProductionSeeder::class);
    }

    public function test_production_seeder_creates_structure_and_exactly_one_login_account(): void
    {
        $this->setEnv('ADMIN_USERNAME', 'realadmin');
        $this->setEnv('ADMIN_PASSWORD', 'a-properly-long-passphrase');
        $this->setEnv('ADMIN_NAME', 'Real Administrator');

        $this->seed(ProductionSeeder::class);

        // Structural reference data the app cannot function without.
        $this->assertSame(3, Department::count());
        $this->assertSame(7, Program::count());

        // The admin, plus the non-login "system" automation account. Crucially
        // NONE of the ~30 demo users DatabaseSeeder would have created.
        $this->assertSame(2, User::count());

        $admin = User::where('username', 'realadmin')->sole();
        $this->assertSame('Real Administrator', $admin->name);
        $this->assertSame('admin', $admin->role);
        $this->assertTrue($admin->is_active);
        $this->assertTrue(Hash::check('a-properly-long-passphrase', $admin->password));

        // The system account must never be signable-in.
        $system = User::where('username', 'system')->sole();
        $this->assertFalse($system->is_active);
        $this->assertFalse(Hash::check('password', $system->password));
        $this->assertFalse(Hash::check('change-this-password', $system->password));
    }

    public function test_production_seeder_is_rerunnable_and_never_resets_an_existing_admin(): void
    {
        $this->setEnv('ADMIN_USERNAME', 'realadmin');
        $this->setEnv('ADMIN_PASSWORD', 'a-properly-long-passphrase');

        $this->seed(ProductionSeeder::class);

        User::where('username', 'realadmin')->sole()->update(['password' => 'changed-after-deploy']);

        $this->seed(ProductionSeeder::class);

        $this->assertSame(2, User::count());
        $this->assertTrue(
            Hash::check('changed-after-deploy', User::where('username', 'realadmin')->sole()->password),
            'Re-seeding clobbered a password the admin had already changed.'
        );
    }

    public function test_demo_set_password_rejects_the_value_documented_in_the_public_repo(): void
    {
        $this->artisan('demo:set-password', ['--password' => 'password'])
            ->expectsOutputToContain('documented in the public repo')
            ->assertExitCode(1);
    }

    public function test_demo_set_password_rejects_a_short_password(): void
    {
        $this->artisan('demo:set-password', ['--password' => 'tooshort'])
            ->expectsOutputToContain('at least 12 characters')
            ->assertExitCode(1);
    }

    public function test_demo_set_password_requires_a_password(): void
    {
        $this->artisan('demo:set-password')
            ->expectsOutputToContain('Refusing to guess')
            ->assertExitCode(1);
    }

    public function test_demo_set_password_rotates_accounts_clears_the_forced_change_and_skips_system(): void
    {
        $student = User::factory()->create([
            'username' => 'mdcstudent',
            'password' => 'password',
            'role' => 'student',
            'must_change_password' => true,
        ]);

        $system = User::factory()->create([
            'username' => 'system',
            'password' => 'password',
            'role' => 'admin',
        ]);

        $this->artisan('demo:set-password', ['--password' => 'defense-day-secret-2026'])
            ->assertExitCode(0);

        $student->refresh();
        $this->assertTrue(Hash::check('defense-day-secret-2026', $student->password));
        $this->assertFalse(
            $student->must_change_password,
            'A forced password change would interrupt a login mid-presentation.'
        );

        $system->refresh();
        $this->assertTrue(
            Hash::check('password', $system->password),
            'The system automation account should have been skipped.'
        );
    }

    public function test_demo_set_password_can_target_a_single_account(): void
    {
        $one = User::factory()->create(['username' => 'mdcadmin', 'password' => 'password']);
        $other = User::factory()->create(['username' => 'mdccore', 'password' => 'password']);

        $this->artisan('demo:set-password', [
            '--password' => 'only-this-one-please',
            '--username' => ['mdcadmin'],
        ])->assertExitCode(0);

        $this->assertTrue(Hash::check('only-this-one-please', $one->refresh()->password));
        $this->assertTrue(Hash::check('password', $other->refresh()->password));
    }
}
