<?php

namespace App\Http\Controllers\Supervisor;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Supervisor\Concerns\ScopesSupervisorWork;
use App\Models\BatchStudent;
use App\Models\WeeklyLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupervisorDashboardController extends Controller
{
    use ScopesSupervisorWork;

    /**
     * Real, supervisor-scoped dashboard stats.
     */
    public function index(Request $request): JsonResponse
    {
        $supervisor = $request->user();
        $studentIds = $this->supervisedStudentIds($supervisor);

        $myInterns = BatchStudent::where('supervisor_id', $supervisor->id)
            ->distinct('student_id')
            ->count('student_id');

        // Reviewable = submitted + still pending (returned/approved are already handled).
        $pendingReviews = WeeklyLog::whereIn('student_id', $studentIds)
            ->whereNotNull('submitted_at')
            ->where('status', 'pending')
            ->count();

        $approvedTotal = WeeklyLog::whereIn('student_id', $studentIds)
            ->where('status', 'approved')
            ->count();

        $returnedTotal = WeeklyLog::whereIn('student_id', $studentIds)
            ->where('status', 'returned')
            ->count();

        $recentlyReviewed = WeeklyLog::whereIn('student_id', $studentIds)
            ->where('supervisor_id', $supervisor->id)
            ->whereNotNull('reviewed_at')
            ->with('student:id,name')
            ->orderByDesc('reviewed_at')
            ->limit(5)
            ->get()
            ->map(fn (WeeklyLog $log) => [
                'id' => $log->id,
                'student_name' => $log->student?->name ?? '',
                'week_start' => $log->week_start->toDateString(),
                'week_end' => $log->week_end->toDateString(),
                'status' => $log->status,
                'reviewed_at' => $log->reviewed_at?->toIso8601String(),
            ]);

        return response()->json([
            'stats' => [
                'my_interns' => $myInterns,
                'pending_reviews' => $pendingReviews,
                'approved_total' => $approvedTotal,
                'returned_total' => $returnedTotal,
            ],
            'recently_reviewed' => $recentlyReviewed,
        ]);
    }
}
