<?php

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
