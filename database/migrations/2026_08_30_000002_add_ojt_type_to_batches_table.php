<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The batch's OJT mechanic: is this cohort supervised by a company account, or
 * reviewed by the coordinator directly?
 *
 * DEFAULT IS 'supervisor', and that is load-bearing rather than arbitrary — it
 * is exactly how every batch behaves today, so every existing row keeps its
 * current mechanics with no backfill and no behaviour change on deploy.
 *
 * It lives on `batches` rather than on the coordinator (the way `dtr_enabled`
 * does) because a cohort is placed under ONE arrangement: a coordinator can
 * genuinely run a supervisor-supported programme and a field-placement one at
 * the same time, and every journal, weekly log and time record already carries
 * its batch_id, so a finished cohort keeps whichever rule it ran under.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            $table->enum('ojt_type', ['supervisor', 'coordinator'])
                ->default('supervisor')
                ->after('journal_template_id');
        });
    }

    public function down(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            $table->dropColumn('ojt_type');
        });
    }
};
