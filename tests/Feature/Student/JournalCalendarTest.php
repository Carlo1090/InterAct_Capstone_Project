<?php

namespace Tests\Feature\Student;

use App\Models\JournalEntry;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Student\Concerns\EnrollsStudentInBatch;
use Tests\TestCase;

class JournalCalendarTest extends TestCase
{
    use EnrollsStudentInBatch;
    use RefreshDatabase;

    /**
     * The most recent past weekend day, so the assertions land inside the OJT
     * range and never on a 'future' day.
     */
    private function mostRecentPastWeekend(): Carbon
    {
        $day = today()->copy()->subDay();

        while (! $day->isWeekend()) {
            $day = $day->subDay();
        }

        return $day;
    }

    public function test_calendar_marks_an_empty_weekend_as_no_entry_and_past_working_day_as_missing(): void
    {
        $student = $this->enrolledStudent([
            'start_date' => now()->subMonths(2),
            'end_date' => now()->addMonths(2),
            'working_days_per_week' => 5,
        ]);
        Sanctum::actingAs($student, ['*']);

        $today = today();

        $weekend = $this->mostRecentPastWeekend();

        $pastWorkday = $today->copy()->subDay();
        while ($pastWorkday->isWeekend()) {
            $pastWorkday = $pastWorkday->subDay();
        }

        $weekendResponse = $this->getJson('/api/student/journal-calendar?month='.$weekend->format('Y-m'));
        $weekendResponse->assertOk();
        $weekendDay = collect($weekendResponse->json('days'))->firstWhere('date', $weekend->toDateString());
        $this->assertSame('no_entry', $weekendDay['status']);

        $workdayResponse = $this->getJson('/api/student/journal-calendar?month='.$pastWorkday->format('Y-m'));
        $workdayResponse->assertOk();
        $workdayDay = collect($workdayResponse->json('days'))->firstWhere('date', $pastWorkday->toDateString());
        $this->assertSame('missing', $workdayDay['status']);
    }

    /**
     * An intern rostered on a Saturday against a 5-day batch: the entry they
     * actually submitted must show as 'submitted', not 'no_entry'. The
     * working-day check used to run BEFORE the entry lookup, so a real Saturday
     * submission rendered identically to "outside your OJT window".
     */
    public function test_calendar_shows_a_submitted_weekend_entry_as_submitted(): void
    {
        $student = $this->enrolledStudent([
            'start_date' => now()->subMonths(2),
            'end_date' => now()->addMonths(2),
            'working_days_per_week' => 5,
        ]);
        Sanctum::actingAs($student, ['*']);

        $weekend = $this->mostRecentPastWeekend();

        JournalEntry::create([
            'student_id' => $student->id,
            'batch_id' => $student->batchEnrollment->batch_id,
            'entry_date' => $weekend->toDateString(),
            'content' => ['daily_accomplishment' => 'Covered the Saturday shift.'],
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        $response = $this->getJson('/api/student/journal-calendar?month='.$weekend->format('Y-m'));
        $response->assertOk();

        $weekendDay = collect($response->json('days'))->firstWhere('date', $weekend->toDateString());
        $this->assertSame('submitted', $weekendDay['status']);
    }

    /**
     * A weekend day with no entry must never be 'missing' — nobody is penalised
     * for not working a weekend. Only work can be added, never absence excused.
     */
    public function test_calendar_never_marks_an_empty_weekend_as_missing(): void
    {
        $student = $this->enrolledStudent([
            'start_date' => now()->subMonths(2),
            'end_date' => now()->addMonths(2),
            'working_days_per_week' => 5,
        ]);
        Sanctum::actingAs($student, ['*']);

        $weekend = $this->mostRecentPastWeekend();

        $response = $this->getJson('/api/student/journal-calendar?month='.$weekend->format('Y-m'));
        $response->assertOk();

        $weekendDay = collect($response->json('days'))->firstWhere('date', $weekend->toDateString());
        $this->assertNotSame('missing', $weekendDay['status']);
        $this->assertSame('no_entry', $weekendDay['status']);
    }

    public function test_calendar_marks_future_date_as_future(): void
    {
        $student = $this->enrolledStudent([
            'start_date' => now()->subMonths(2),
            'end_date' => now()->addMonths(2),
        ]);
        Sanctum::actingAs($student, ['*']);

        $future = today()->addDays(5);

        $response = $this->getJson('/api/student/journal-calendar?month='.$future->format('Y-m'));
        $response->assertOk();

        $futureDay = collect($response->json('days'))->firstWhere('date', $future->toDateString());
        $this->assertSame('future', $futureDay['status']);
    }
}
