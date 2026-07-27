<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Rotates the shared password on every seeded demo account.
 *
 * Needed because this repo is public and its CLAUDE.md documents both the demo
 * usernames (mdcadmin, mdccore, mdcbalbero, mdcstudent, mdcsupervisor, ...) and
 * the fact that they all use "password". A demo deployment seeded with
 * DatabaseSeeder is therefore trivially takeover-able by anyone who finds the
 * repo and the URL — including as admin.
 *
 * Run this immediately after seeding a demo deployment:
 *
 *   php artisan demo:set-password --password="something-not-in-the-repo"
 *
 * It touches every account except the non-login "system" automation user, and
 * clears must_change_password so a demo login is not interrupted by the forced
 * password-change flow mid-presentation.
 */
class SetDemoPassword extends Command
{
    protected $signature = 'demo:set-password
                            {--password= : The shared password to set. Must be 12+ characters.}
                            {--username=* : Limit to specific usernames instead of every account.}';

    protected $description = 'Rotate the shared password on seeded demo accounts (public repo documents the default).';

    public function handle(): int
    {
        $password = (string) $this->option('password');

        if (blank($password)) {
            $this->error('Pass --password="...". Refusing to guess.');

            return self::FAILURE;
        }

        // Checked before the length rule on purpose: "password" is 8 characters,
        // so a bare length error would hide the far more useful reason.
        if ($password === 'password') {
            $this->error('That is the value documented in the public repo. Pick another.');

            return self::FAILURE;
        }

        if (mb_strlen($password) < 12) {
            $this->error('Use at least 12 characters — this deployment is internet-facing.');

            return self::FAILURE;
        }

        $usernames = $this->option('username');

        $query = User::query()->where('username', '!=', 'system');

        if (! empty($usernames)) {
            $query->whereIn('username', $usernames);
        }

        $users = $query->get();

        if ($users->isEmpty()) {
            $this->warn('No matching accounts found. Nothing changed.');

            return self::SUCCESS;
        }

        foreach ($users as $user) {
            // `password` is cast to 'hashed' on the User model, so the plain
            // value is hashed on write — one distinct salt per account rather
            // than a single shared hash. Both columns are fillable.
            $user->update([
                'password' => $password,
                'must_change_password' => false,
            ]);
        }

        $this->info("Rotated the password on {$users->count()} account(s).");
        $this->line('Accounts touched: '.$users->pluck('username')->implode(', '));
        $this->newLine();
        $this->warn('The non-login "system" account was skipped and keeps its unusable password.');

        return self::SUCCESS;
    }
}
