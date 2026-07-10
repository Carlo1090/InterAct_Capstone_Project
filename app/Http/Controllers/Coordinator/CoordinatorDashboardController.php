<?php

namespace App\Http\Controllers\Coordinator;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\JournalEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CoordinatorDashboardController extends Controller
{
    /**
     * Statuses that represent a daily journal the student failed to submit.
     */
    private const MISSING_STATUSES = ['missing', 'overdue'];

    /**
     * Real, department-scoped dashboard stats for the signed-in coordinator.
     */
    public function index(Request $request): JsonResponse
    {
        $programIds = $request->user()->coordinatorProgramIds();

        $weekStart = now()->startOfWeek()->toDateString(); // Monday
        $today = now()->toDateString();

        // (a) My interns = active enrollments within scope.
        $activeInterns = BatchStudent::where('status', 'active')
            ->whereHas('batch', fn ($query) => $query->whereIn('program_id', $programIds))
            ->count();

        // (b) Journals submitted vs missing this week (Mon–today), within scope.
        // whereDate keeps same-day matches correct across DB drivers (SQLite
        // stores a time component on the date column, MySQL does not).
        $entriesThisWeek = fn () => JournalEntry::whereDate('entry_date', '>=', $weekStart)
            ->whereDate('entry_date', '<=', $today)
            ->whereHas('batch', fn ($query) => $query->whereIn('program_id', $programIds));

        $journalsSubmitted = $entriesThisWeek()->where('status', 'submitted')->count();
        $journalsMissing = $entriesThisWeek()->whereIn('status', self::MISSING_STATUSES)->count();

        // (c) Active batches within scope.
        $activeBatches = Batch::whereIn('program_id', $programIds)
            ->where('is_active', true)
            ->count();

        // (d) Students behind = in-scope active interns with ≥1 missing entry this week.
        $missingByStudent = JournalEntry::whereDate('entry_date', '>=', $weekStart)
            ->whereDate('entry_date', '<=', $today)
            ->whereIn('status', self::MISSING_STATUSES)
            ->whereHas('batch', fn ($query) => $query->whereIn('program_id', $programIds))
            ->select('student_id', DB::raw('count(*) as missing_count'))
            ->groupBy('student_id')
            ->pluck('missing_count', 'student_id');

        $behind = BatchStudent::where('status', 'active')
            ->whereIn('student_id', $missingByStudent->keys())
            ->whereHas('batch', fn ($query) => $query->whereIn('program_id', $programIds))
            ->with(['student:id,name', 'company:id,name'])
            ->get()
            ->map(fn (BatchStudent $enrollment) => [
                'student_id' => $enrollment->student_id,
                'name' => $enrollment->student?->name ?? '',
                'company' => $enrollment->company?->name ?? '',
                'missing_count' => (int) ($missingByStudent[$enrollment->student_id] ?? 0),
            ])
            ->sortByDesc('missing_count')
            ->values();

        return response()->json([
            'stats' => [
                'active_interns' => $activeInterns,
                'journals_submitted_this_week' => $journalsSubmitted,
                'journals_missing_this_week' => $journalsMissing,
                'active_batches' => $activeBatches,
                'students_behind' => $behind->count(),
            ],
            'students_behind' => $behind,
            'week' => [
                'start' => $weekStart,
                'end' => $today,
            ],
        ]);
    }
}
