<?php

use App\Console\Commands\RunWeeklyBundling;
use App\Console\Commands\SendMissingJournalEntryReminders;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Runs daily at the schema's default daily_reminder_time (21:00). Per-batch
// custom reminder times are a future refinement.
Schedule::command(SendMissingJournalEntryReminders::class)->dailyAt('21:00');

// Saturday 00:00 — compiles the Mon-Fri week that just ended. weeklyOn(6, ...)
// because Carbon/Laravel's day numbering is 0=Sunday..6=Saturday.
Schedule::command(RunWeeklyBundling::class)->weeklyOn(6, '00:00');
