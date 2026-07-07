<?php

namespace Tests\Feature\Student;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Student\Concerns\EnrollsStudentInBatch;
use Tests\TestCase;

class JournalCalendarTest extends TestCase
{
    use RefreshDatabase;
    use EnrollsStudentInBatch;

    public function test_calendar_marks_weekend_as_no_entry_and_past_working_day_as_missing(): void
    {
        $student = $this->enrolledStudent([
            'start_date' => now()->subMonths(2),
            'end_date' => now()->addMonths(2),
            'working_days_per_week' => 5,
        ]);
        Sanctum::actingAs($student, ['*']);

        $today = today();

        $weekend = $today->copy()->subDay();
        while (! $weekend->isWeekend()) {
            $weekend = $weekend->subDay();
        }

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
