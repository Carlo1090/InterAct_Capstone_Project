<?php

namespace App\Http\Controllers\Coordinator;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\JournalEntry;
use App\Support\BatchWorkingDays;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class CoordinatorDashboardController extends Controller
{
    /**
     * Real, department-scoped dashboard stats for the signed-in coordinator.
     */
    public function index(Request $request): JsonResponse
    {
        $programIds = $request->user()->coordinatorProgramIds();

        $today = today();
        $weekStart = $today->copy()->startOfWeek(CarbonInterface::MONDAY);

        // (a) My interns = active enrollments within scope.
        $enrollments = BatchStudent::where('status', 'active')
            ->whereHas('batch', fn ($query) => $query->whereIn('program_id', $programIds))
            ->with([
                'student:id,name',
                'company:id,name',
                'batch:id,program_id,start_date,working_days_start,working_days_end',
                'batch.program:id,code',
            ])
            ->get();

        // (b) Journals submitted this week (Mon–today), within scope.
        // whereDate keeps same-day matches correct across DB drivers (SQLite
        // stores a time component on the date column, MySQL does not).
        $journalsSubmitted = JournalEntry::where('status', 'submitted')
            ->whereDate('entry_date', '>=', $weekStart->toDateString())
            ->whereDate('entry_date', '<=', $today->toDateString())
            ->whereHas('batch', fn ($query) => $query->whereIn('program_id', $programIds))
            ->count();

        // (c) Active batches within scope.
        $activeBatches = Batch::whereIn('program_id', $programIds)
            ->where('is_active', true)
            ->count();

        // (d) Students behind = in-scope active interns with ≥1 missing working
        // day this week. "Missing" is DERIVED — the absence of a submitted entry
        // on a working day — because journal_entries.status only ever stores
        // draft/submitted; querying it for missing/overdue always returned zero.
        $submittedDates = $this->submittedDatesThisWeek($enrollments, $weekStart, $today);

        $behind = $enrollments
            ->map(fn (BatchStudent $enrollment) => [
                'student_id' => $enrollment->student_id,
                'name' => $enrollment->student?->name ?? '',
                'program' => $enrollment->batch?->program?->code ?? '',
                'company' => $enrollment->company?->name ?? '',
                'missing_count' => $this->countMissingWorkingDays(
                    $enrollment,
                    $submittedDates[$enrollment->student_id.':'.$enrollment->batch_id] ?? [],
                    $weekStart,
                    $today,
                ),
            ])
            ->filter(fn (array $row) => $row['missing_count'] > 0)
            ->sort(fn (array $a, array $b) => [$b['missing_count'], $a['name']] <=> [$a['missing_count'], $b['name']])
            ->values();

        return response()->json([
            'stats' => [
                'active_interns' => $enrollments->count(),
                'journals_submitted_this_week' => $journalsSubmitted,
                'journals_missing_this_week' => $behind->sum('missing_count'),
                'active_batches' => $activeBatches,
                'students_behind' => $behind->count(),
            ],
            'students_behind' => $behind,
            'week' => [
                'start' => $weekStart->toDateString(),
                'end' => $today->toDateString(),
            ],
        ]);
    }

    /**
     * Every submitted entry date this week for the given enrollments, in ONE
     * query, keyed "student_id:batch_id" => [Y-m-d => true]. Keyed by the pair
     * rather than the student so an entry filed under a previous batch never
     * covers a day owed to the current one.
     *
     * @param  Collection<int, BatchStudent>  $enrollments
     * @return array<string, array<string, true>>
     */
    private function submittedDatesThisWeek(Collection $enrollments, CarbonInterface $weekStart, CarbonInterface $today): array
    {
        if ($enrollments->isEmpty()) {
            return [];
        }

        $dates = [];

        JournalEntry::where('status', 'submitted')
            ->whereIn('student_id', $enrollments->pluck('student_id')->unique())
            ->whereIn('batch_id', $enrollments->pluck('batch_id')->unique())
            ->whereDate('entry_date', '>=', $weekStart->toDateString())
            ->whereDate('entry_date', '<=', $today->toDateString())
            ->get(['student_id', 'batch_id', 'entry_date'])
            ->each(function (JournalEntry $entry) use (&$dates) {
                $dates[$entry->student_id.':'.$entry->batch_id][$entry->entry_date->toDateString()] = true;
            });

        return $dates;
    }

    /**
     * The same rule as StudentDashboardController::countMissingWorkingDays():
     * from this week's Monday or the batch start, whichever is later, through
     * today, count each working day of the batch with no submitted entry.
     *
     * @param  array<string, true>  $submittedDates
     */
    private function countMissingWorkingDays(BatchStudent $enrollment, array $submittedDates, CarbonInterface $weekStart, CarbonInterface $today): int
    {
        $batch = $enrollment->batch;

        if (! $batch) {
            return 0;
        }

        $batchStart = $batch->start_date;
        $cursor = $batchStart && $batchStart->greaterThan($weekStart) ? $batchStart->copy() : $weekStart->copy();
        $missing = 0;

        while ($cursor->lte($today)) {
            if (BatchWorkingDays::isWorkingDayInRange($cursor, (int) $batch->working_days_start, (int) $batch->working_days_end)
                && ! isset($submittedDates[$cursor->toDateString()])) {
                $missing++;
            }

            $cursor = $cursor->copy()->addDay();
        }

        return $missing;
    }
}
