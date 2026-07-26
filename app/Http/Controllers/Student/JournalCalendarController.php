<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Student\Concerns\ResolvesStudentEnrollment;
use App\Models\BatchStudent;
use App\Models\JournalEntry;
use App\Support\BatchWorkingDays;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JournalCalendarController extends Controller
{
    use ResolvesStudentEnrollment;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $enrollment = $this->currentEnrollment($user->id);

        if (! $enrollment) {
            return response()->json(['message' => 'You are not currently enrolled in an active OJT batch.'], 422);
        }

        $monthParam = $request->query('month');
        $month = $monthParam
            ? CarbonImmutable::createFromFormat('Y-m-d', $monthParam.'-01')->startOfMonth()
            : CarbonImmutable::now()->startOfMonth();

        $range = $this->ojtRange($enrollment);
        $today = today();
        $workingDaysPerWeek = $enrollment->batch->working_days_per_week;

        $entriesByDate = JournalEntry::where('student_id', $user->id)
            // whereDate on both bounds, never whereBetween: entry_date is a
            // date-cast column and SQLite stores it with a time component, which
            // sorts after a bare upper bound and drops the month's last day.
            ->whereDate('entry_date', '>=', $month->startOfMonth()->toDateString())
            ->whereDate('entry_date', '<=', $month->endOfMonth()->toDateString())
            ->get()
            ->keyBy(fn (JournalEntry $entry) => $entry->entry_date->toDateString());

        $days = [];
        $cursor = $month->startOfMonth();
        $end = $month->endOfMonth();

        while ($cursor->lessThanOrEqualTo($end)) {
            $days[] = [
                'date' => $cursor->toDateString(),
                'status' => $this->statusFor($cursor, $range, $workingDaysPerWeek, $today, $enrollment, $entriesByDate->get($cursor->toDateString())),
            ];
            $cursor = $cursor->addDay();
        }

        return response()->json([
            'month' => $month->format('Y-m'),
            'days' => $days,
        ]);
    }

    /**
     * @param  array{start: CarbonInterface, end: CarbonInterface}  $range
     */
    private function statusFor(CarbonImmutable $date, array $range, int $workingDaysPerWeek, CarbonInterface $today, BatchStudent $enrollment, ?JournalEntry $entry): string
    {
        if ($date->lt($range['start'])) {
            return 'no_entry';
        }

        // Completion freezes the window: days past completed_at are outside
        // the OJT range for good. While the enrollment is still open the
        // upper bound is rolling, so days after today stay 'future' — never
        // 'no_entry'.
        if ($enrollment->status === 'completed' && $date->gt($range['end'])) {
            return 'no_entry';
        }

        if ($date->gt($today)) {
            return 'future';
        }

        // An entry the student actually wrote always wins, whatever day of the
        // week it falls on — an intern who worked a Saturday must not see their
        // submitted entry reported as 'no_entry' (which reads identically to
        // "outside your OJT window").
        if ($entry?->status === 'submitted') {
            return 'submitted';
        }

        if ($entry?->status === 'draft') {
            return 'draft';
        }

        // Nothing written. Only now does the schedule matter: a non-working day
        // was never expected, so it stays 'no_entry' and is never 'missing'.
        if (! BatchWorkingDays::isWorkingDay($date, $workingDaysPerWeek)) {
            return 'no_entry';
        }

        return $date->lt($today) ? 'missing' : 'draft';
    }
}
