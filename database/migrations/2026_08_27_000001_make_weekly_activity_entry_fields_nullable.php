<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Weekly Activity Log grid auto-saves as the student types, but a row
 * could only be created once BOTH dates and the Activities text were present,
 * because these three columns were NOT NULL. So a half-filled row — dates
 * typed, activities not yet — lived only in the browser, and closing the tab
 * or logging out threw it away with nothing on screen saying it would.
 *
 * Relaxing the three columns lets a partially-filled row persist the moment
 * anything is typed in it. "At least one field is non-empty" is enforced in
 * StoreWeeklyActivityEntryRequest instead, so an entirely blank row is still
 * refused — the check moves up a layer rather than disappearing.
 *
 * Additive and reversible: no column is renamed or dropped, and every existing
 * row already satisfies the tighter shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('weekly_activity_entries', function (Blueprint $table) {
            $table->date('inclusive_date_start')->nullable()->change();
            $table->date('inclusive_date_end')->nullable()->change();
            $table->text('activities')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('weekly_activity_entries', function (Blueprint $table) {
            $table->date('inclusive_date_start')->nullable(false)->change();
            $table->date('inclusive_date_end')->nullable(false)->change();
            $table->text('activities')->nullable(false)->change();
        });
    }
};
