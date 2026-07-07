<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Student\Concerns\ResolvesStudentEnrollment;
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
        $enrollment = $this->activeEnrollment($user->id);

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
            ->whereBetween('entry_date', [$month->startOfMonth()->toDateString(), $month->endOfMonth()->toDateString()])
            ->get()
            ->keyBy(fn (JournalEntry $entry) => $entry->entry_date->toDateString());

        $days = [];
        $cursor = $month->startOfMonth();
        $end = $month->endOfMonth();

        while ($cursor->lessThanOrEqualTo($end)) {
            $days[] = [
                'date' => $cursor->toDateString(),
                'status' => $this->statusFor($cursor, $range, $workingDaysPerWeek, $today, $entriesByDate->get($cursor->toDateString())),
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
    private function statusFor(CarbonImmutable $date, array $range, int $workingDaysPerWeek, CarbonInterface $today, ?JournalEntry $entry): string
    {
        if ($date->lt($range['start']) || $date->gt($range['end'])) {
            return 'no_entry';
        }

        if ($date->gt($today)) {
            return 'future';
        }

        if (! BatchWorkingDays::isWorkingDay($date, $workingDaysPerWeek)) {
            return 'no_entry';
        }

        if ($entry?->status === 'submitted') {
            return 'submitted';
        }

        if ($entry?->status === 'draft') {
            return 'draft';
        }

        return $date->lt($today) ? 'missing' : 'draft';
    }
}
