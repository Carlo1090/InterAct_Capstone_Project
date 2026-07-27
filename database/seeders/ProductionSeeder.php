<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * The seeder a real deployment runs. DatabaseSeeder is NOT it — that one calls
 * twelve demo seeders and creates ~30 fictional users whose shared password is
 * documented in this repo's CLAUDE.md, which is public. Running `db:seed` with
 * no --class on a public deployment therefore hands anyone who finds both the
 * repo and the URL an admin login.
 *
 * This seeds only what the app genuinely cannot function without:
 *   - the 3 departments and 7 programs (structural reference data)
 *   - exactly one admin account, whose credentials come from the environment
 *
 * Everything else is created through the app by real staff: the admin creates
 * coordinators, coordinators create students and supervisors.
 *
 *   php artisan db:seed --class=ProductionSeeder --force
 *
 * Requires ADMIN_USERNAME and ADMIN_PASSWORD to be set. It refuses to invent a
 * default password rather than silently creating a guessable admin.
 */
class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            DepartmentProgramSeeder::class,
        ]);

        $username = env('ADMIN_USERNAME');
        $password = env('ADMIN_PASSWORD');

        if (blank($username) || blank($password)) {
            throw new RuntimeException(
                'ProductionSeeder needs ADMIN_USERNAME and ADMIN_PASSWORD in the environment. '.
                'Set both, then re-run. Refusing to create an admin with a default password.'
            );
        }

        if (mb_strlen((string) $password) < 12) {
            throw new RuntimeException(
                'ADMIN_PASSWORD must be at least 12 characters. This is the one account that '.
                'can create every other account, and the deployment is internet-facing.'
            );
        }

        // The schema's implementation notes reserve a non-login "System" account
        // for auto-triggered actions (weekly bundling, reminder emails) so those
        // system_logs rows have an author. It is deliberately given an unusable
        // password rather than a known one — nothing ever signs in as it.
        User::firstOrCreate(
            ['username' => 'system'],
            [
                'name' => 'System',
                'email' => null,
                'password' => Hash::make(bin2hex(random_bytes(32))),
                'role' => 'admin',
                'is_active' => false,
            ]
        );

        $admin = User::firstOrCreate(
            ['username' => $username],
            [
                'name' => env('ADMIN_NAME', 'Administrator'),
                'email' => env('ADMIN_EMAIL') ?: null,
                'password' => Hash::make($password),
                'role' => 'admin',
                'is_active' => true,
            ]
        );

        $this->command?->info($admin->wasRecentlyCreated
            ? "Created admin account: {$admin->username}"
            : "Admin account {$admin->username} already existed — left untouched.");
    }
}
