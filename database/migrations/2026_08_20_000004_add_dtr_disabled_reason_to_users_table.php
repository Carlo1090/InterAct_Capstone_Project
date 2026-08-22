<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why a coordinator's programme does not use the QR Daily Time Record.
 *
 * dtr_enabled already records THAT a programme opted out; these record WHY, so
 * an admin auditing a department — or the next coordinator to inherit it —
 * does not have to guess whether the switch is off deliberately or by neglect.
 *
 * Deliberately NOT a third state on dtr_enabled. That column is boolean NOT
 * NULL default false and the admin sets it definitively on the Create
 * Coordinator form, which is what keeps resolution a single clean hop
 * (batch_students.batch_id -> batches.coordinator_id -> users.dtr_enabled).
 * Making it nullable to represent "never answered" would put a null check on
 * every read of that path for the sake of a first-run prompt.
 *
 * Both columns are nullable and meaningful only while dtr_enabled is false;
 * DtrPreferenceController clears them whenever the DTR is switched back on, so
 * a stale reason can never outlive the decision it explained.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // A slug from User::DTR_DISABLED_REASONS, not free text — the
            // whole point is that an admin can compare departments, which a
            // hand-typed sentence per coordinator would not allow.
            $table->string('dtr_disabled_reason', 40)->nullable()->after('dtr_enabled');
            // The free-text half, for the specifics a fixed list cannot carry
            // ("BSTM interns rotate across three resort branches").
            $table->string('dtr_disabled_note', 255)->nullable()->after('dtr_disabled_reason');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['dtr_disabled_reason', 'dtr_disabled_note']);
        });
    }
};
