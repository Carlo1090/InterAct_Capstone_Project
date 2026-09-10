<?php

namespace Tests\Unit\Support;

use App\Support\BatchWorkingDays;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class BatchWorkingDaysTest extends TestCase
{
    /** ISO weekday $iso (1=Mon..7=Sun) in the current week, so this never depends on today's actual date. */
    private function isoDay(int $iso): Carbon
    {
        return Carbon::now()->startOfWeek(Carbon::MONDAY)->addDays($iso - 1);
    }

    /**
     * Pins that isWorkingDayInRange() reproduces isWorkingDay()'s three old
     * count-only cases exactly — the migration's backfill relies on this
     * mapping being genuinely behavior-preserving for every existing batch.
     */
    public function test_a_monday_anchored_range_matches_the_old_count_based_rule(): void
    {
        // 1..5 (Mon-Fri)
        foreach (range(1, 5) as $iso) {
            $this->assertTrue(BatchWorkingDays::isWorkingDayInRange($this->isoDay($iso), 1, 5));
        }
        $this->assertFalse(BatchWorkingDays::isWorkingDayInRange($this->isoDay(6), 1, 5));
        $this->assertFalse(BatchWorkingDays::isWorkingDayInRange($this->isoDay(7), 1, 5));

        // 1..6 (Mon-Sat)
        foreach (range(1, 6) as $iso) {
            $this->assertTrue(BatchWorkingDays::isWorkingDayInRange($this->isoDay($iso), 1, 6));
        }
        $this->assertFalse(BatchWorkingDays::isWorkingDayInRange($this->isoDay(7), 1, 6));

        // 1..7 (every day)
        foreach (range(1, 7) as $iso) {
            $this->assertTrue(BatchWorkingDays::isWorkingDayInRange($this->isoDay($iso), 1, 7));
        }
    }

    /** Sat(6) -> Tue(2): Sat, Sun, Mon, Tue true; Wed-Fri false. */
    public function test_a_wrapped_range_covers_the_days_across_the_week_boundary(): void
    {
        $this->assertTrue(BatchWorkingDays::isWorkingDayInRange($this->isoDay(6), 6, 2));
        $this->assertTrue(BatchWorkingDays::isWorkingDayInRange($this->isoDay(7), 6, 2));
        $this->assertTrue(BatchWorkingDays::isWorkingDayInRange($this->isoDay(1), 6, 2));
        $this->assertTrue(BatchWorkingDays::isWorkingDayInRange($this->isoDay(2), 6, 2));

        $this->assertFalse(BatchWorkingDays::isWorkingDayInRange($this->isoDay(3), 6, 2));
        $this->assertFalse(BatchWorkingDays::isWorkingDayInRange($this->isoDay(4), 6, 2));
        $this->assertFalse(BatchWorkingDays::isWorkingDayInRange($this->isoDay(5), 6, 2));
    }

    public function test_a_single_day_range_matches_only_that_day(): void
    {
        $this->assertTrue(BatchWorkingDays::isWorkingDayInRange($this->isoDay(3), 3, 3));
        $this->assertFalse(BatchWorkingDays::isWorkingDayInRange($this->isoDay(2), 3, 3));
        $this->assertFalse(BatchWorkingDays::isWorkingDayInRange($this->isoDay(4), 3, 3));
    }

    public function test_range_from_legacy_count_matches_the_old_isWorkingDay_bands(): void
    {
        $this->assertSame([1, 5], BatchWorkingDays::rangeFromLegacyCount(5));
        $this->assertSame([1, 5], BatchWorkingDays::rangeFromLegacyCount(3));
        $this->assertSame([1, 5], BatchWorkingDays::rangeFromLegacyCount(1));
        $this->assertSame([1, 6], BatchWorkingDays::rangeFromLegacyCount(6));
        $this->assertSame([1, 7], BatchWorkingDays::rangeFromLegacyCount(7));
    }

    public function test_count_from_range_round_trips_a_normal_range(): void
    {
        $this->assertSame(5, BatchWorkingDays::countFromRange(1, 5));
        $this->assertSame(6, BatchWorkingDays::countFromRange(1, 6));
        $this->assertSame(7, BatchWorkingDays::countFromRange(1, 7));
        $this->assertSame(1, BatchWorkingDays::countFromRange(3, 3));
    }

    /** Sat(6) -> Tue(2) spans 4 days: Sat, Sun, Mon, Tue. */
    public function test_count_from_range_counts_a_wrapped_range_correctly(): void
    {
        $this->assertSame(4, BatchWorkingDays::countFromRange(6, 2));
    }
}
