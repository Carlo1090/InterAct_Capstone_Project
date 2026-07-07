<?php

namespace App\Support;

use Carbon\CarbonInterface;

class BatchWorkingDays
{
    public static function isWorkingDay(CarbonInterface $date, int $workingDaysPerWeek): bool
    {
        $dayOfWeek = $date->dayOfWeekIso; // 1 (Mon) ... 7 (Sun)

        if ($workingDaysPerWeek >= 7) {
            return true;
        }

        if ($workingDaysPerWeek === 6) {
            return $dayOfWeek !== 7;
        }

        return $dayOfWeek < 6;
    }
}
