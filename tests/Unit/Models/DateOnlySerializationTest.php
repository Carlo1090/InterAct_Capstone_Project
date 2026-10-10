<?php

namespace Tests\Unit\Models;

use App\Models\Batch;
use App\Models\DtrSession;
use App\Models\JournalEntry;
use App\Models\StudentProfile;
use App\Models\WeeklyActivityEntry;
use App\Models\WeeklyActivityLog;
use App\Models\WeeklyLog;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A date-only column must reach the client as the date it holds.
 *
 * Found 2026-10-10 on screen: once the app timezone became Asia/Manila, a
 * plain `date` cast read 2026-10-10 as Manila midnight, and Laravel serialises
 * every Carbon as UTC — "2026-10-09T16:00:00.000000Z". The SPA and the mobile
 * app both take a date as its first ten characters, so a journal written for
 * the 10th was listed under the 9th, and the Edit Batch form loaded each date
 * a day early and saved it back that way. phpunit pins APP_TIMEZONE=UTC, where
 * midnight serialises as midnight, which is why nothing else in the suite saw
 * it — so this test switches to Manila itself.
 */
class DateOnlySerializationTest extends TestCase
{
    private string $previousTimezone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousTimezone = date_default_timezone_get();
        config(['app.timezone' => 'Asia/Manila']);
        date_default_timezone_set('Asia/Manila');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->previousTimezone);
        parent::tearDown();
    }

    public static function dateOnlyColumns(): array
    {
        return [
            'batch start' => [Batch::class, 'start_date'],
            'batch end' => [Batch::class, 'end_date'],
            'dtr work date' => [DtrSession::class, 'work_date'],
            'journal entry date' => [JournalEntry::class, 'entry_date'],
            'date of birth' => [StudentProfile::class, 'date_of_birth'],
            'activity entry start' => [WeeklyActivityEntry::class, 'inclusive_date_start'],
            'activity entry end' => [WeeklyActivityEntry::class, 'inclusive_date_end'],
            'activity log start' => [WeeklyActivityLog::class, 'week_start'],
            'activity log end' => [WeeklyActivityLog::class, 'week_end'],
            'weekly log start' => [WeeklyLog::class, 'week_start'],
            'weekly log end' => [WeeklyLog::class, 'week_end'],
        ];
    }

    /**
     * @param  class-string<Model>  $model
     */
    #[DataProvider('dateOnlyColumns')]
    public function test_a_date_only_column_serialises_as_its_own_date_in_manila(string $model, string $column): void
    {
        $instance = new $model;
        $instance->setRawAttributes([$column => '2026-10-10']);

        $this->assertSame('2026-10-10', $instance->toArray()[$column]);
        // Reading it in PHP is unchanged: still a Carbon at that date.
        $this->assertSame('2026-10-10', $instance->{$column}->toDateString());
    }
}
