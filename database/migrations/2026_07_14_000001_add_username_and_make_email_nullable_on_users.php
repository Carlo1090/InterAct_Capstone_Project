<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Enrollment is moving to username-based credentials (email is parked for
     * future use). Add a NOT-NULL, unique `username`, backfilling existing rows
     * a deterministic value from their email local-part so seeded/demo accounts
     * keep working; make `email` nullable (still unique-when-present).
     */
    public function up(): void
    {
        // 1. Add nullable first so existing rows don't violate NOT NULL.
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->nullable()->after('name');
        });

        // 2. Backfill deterministic, unique usernames for existing rows.
        $used = [];

        foreach (DB::table('users')->select('id', 'email', 'name', 'role')->orderBy('id')->get() as $row) {
            $base = $row->email ? Str::before($row->email, '@') : ($row->name ?: $row->role);
            $base = Str::of((string) $base)->lower()->replaceMatches('/[^a-z0-9._-]+/', '')->trim('._-')->value();

            if ($base === '') {
                $base = 'user';
            }

            $candidate = $base;
            $suffix = 1;

            while (in_array($candidate, $used, true) || DB::table('users')->where('username', $candidate)->exists()) {
                $candidate = $base.$suffix;
                $suffix++;
            }

            $used[] = $candidate;
            DB::table('users')->where('id', $row->id)->update(['username' => $candidate]);
        }

        // 3. Enforce NOT NULL, then add the unique index.
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->nullable(false)->change();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unique('username');
        });

        // 4. Email is now optional (its existing unique index is preserved;
        //    both MySQL and SQLite allow multiple NULLs under a unique index).
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
        });
    }
};
