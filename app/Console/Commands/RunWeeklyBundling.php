<?php

namespace App\Console\Commands;

use App\Services\WeeklyBundlingService;
use Illuminate\Console\Command;

class RunWeeklyBundling extends Command
{
    protected $signature = 'journal:run-weekly-bundling {--week-start= : Y-m-d date within the target week; defaults to the most recently completed Mon-Fri}';

    protected $description = "Compile each active student's Mon-Fri Daily Accomplishment entries into their WeeklyLog narrative.";

    public function handle(WeeklyBundlingService $service): int
    {
        $weekStart = $this->option('week-start') ?: WeeklyBundlingService::mostRecentlyCompletedWeekStart();

        $result = $service->bundleWeek($weekStart);

        $this->info(
            "Weekly bundling complete for {$result['week_start']} to {$result['week_end']}. ".
            "Compiled: {$result['compiled']}, skipped (already submitted): {$result['skipped_submitted']}."
        );

        return self::SUCCESS;
    }
}
