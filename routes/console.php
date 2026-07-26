<?php

use App\Console\Commands\PurgeArchivedBatchStudents;
use App\Console\Commands\RunWeeklyBundling;
use App\Console\Commands\SendMissingJournalEntryReminders;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Hourly, NOT daily: each student can pick their own reminder hour (and their
// own reminder days, weekends included), so the command decides whose hour it
// currently is. It resolves each student's preference over the batch's
// daily_reminder_time — which, before this, nothing read at all.
Schedule::command(SendMissingJournalEntryReminders::class)->hourly();

// Monday 00:00 — compiles the full Mon-Sun week that just ended. weeklyOn(1, ...)
// because Carbon/Laravel's day numbering is 0=Sunday..6=Saturday.
//
// Deliberately NOT Saturday: bundling stamps a WeeklyLog, and that is a one-way
// edit lock on every daily entry in the week (JournalEntryController::isBundledWeek).
// Running it Saturday 00:00 locked the week before a Saturday shift had even
// started, so an intern who worked that day could never write the entry.
Schedule::command(RunWeeklyBundling::class)->weeklyOn(1, '00:00');

// Nightly — permanently deletes batch_students rows archived 30+ days ago.
Schedule::command(PurgeArchivedBatchStudents::class)->dailyAt('02:00');
