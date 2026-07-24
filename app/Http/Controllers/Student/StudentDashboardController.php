<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Student\Concerns\ResolvesStudentEnrollment;
use App\Models\JournalEntry;
use App\Models\SystemLog;
use App\Models\WeeklyLog;
use App\Support\BatchWorkingDays;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class StudentDashboardController extends Controller
{
    use ResolvesStudentEnrollment;

    /**
     * Real, own-scope dashboard for the signed-in student — replaces the
     * previous static mock data. Only reachable once enrolled (the SPA
     * router redirects a gated/paused student away from this page before
     * it ever calls this endpoint), but still degrades to a 422 like every
     * other student endpoint if hit directly with no active enrollment.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $enrollment = $this->currentEnrollment($user->id);

        if (! $enrollment) {
            return response()->json(['message' => 'You are not currently enrolled in an active OJT batch.'], 422);
        }

        $enrollment->loadMissing(['batch.program.department', 'batch.coordinator', 'company', 'supervisor']);
        $batch = $enrollment->batch;
        $range = $this->ojtRange($enrollment);
        $today = today();

        $weekStart = now()->startOfWeek()->toDateString();
        $weekEnd = $today->toDateString();

        $entriesSubmittedTotal = JournalEntry::where('student_id', $user->id)
            ->where('batch_id', $batch->id)
            ->where('status', 'submitted')
            ->count();

        $missingThisWeek = $this->countMissingWorkingDays($user->id, $batch->id, $range['start'], $today, $batch->working_days_per_week);

        $weeklyLogsApproved = WeeklyLog::where('student_id', $user->id)
            ->where('batch_id', $batch->id)
            ->where('status', 'approved')
            ->count();

        $weeklyLogsPending = WeeklyLog::where('student_id', $user->id)
            ->where('batch_id', $batch->id)
            ->whereNotNull('submitted_at')
            ->where('status', 'pending')
            ->count();

        $weeksElapsed = max(1, (int) floor($range['start']->diffInWeeks($today)) + 1);
        $weeklyApprovalPercent = (int) round(min($weeklyLogsApproved, $weeksElapsed) / $weeksElapsed * 100);

        $totalPlannedDays = max(1, $batch->start_date->diffInDays($batch->end_date));
        $daysElapsed = min($totalPlannedDays, max(0, $batch->start_date->diffInDays($today, false)));
        $durationPercent = (int) round(($daysElapsed / $totalPlannedDays) * 100);

        $recentActivity = SystemLog::where('user_id', $user->id)
            ->orderByDesc('logged_at')
            ->limit(5)
            ->get()
            ->map(fn (SystemLog $log) => [
                'text' => $log->description ?? $log->action,
                'time' => $log->logged_at ? Carbon::parse($log->logged_at)->diffForHumans() : null,
                'tone' => $this->toneForAction($log->action),
            ]);

        return response()->json([
            'stats' => [
                'entries_submitted_total' => $entriesSubmittedTotal,
                'weekly_logs_approved' => $weeklyLogsApproved,
                'weekly_logs_pending' => $weeklyLogsPending,
                'missing_this_week' => $missingThisWeek,
            ],
            'progress' => [
                'weekly_reports_approved_percent' => max(0, min(100, $weeklyApprovalPercent)),
                'ojt_duration_percent' => max(0, min(100, $durationPercent)),
            ],
            'recent_activity' => $recentActivity,
            'internship' => [
                'host_company' => $enrollment->company?->name,
                'supervisor' => $enrollment->supervisor?->name,
                'coordinator' => $batch->coordinator?->name,
                'department' => $batch->program?->department?->name,
                'program' => $batch->program?->name,
                'start_date' => $batch->start_date?->toDateString(),
            ],
            'week' => [
                'start' => $weekStart,
                'end' => $weekEnd,
            ],
        ]);
    }

    /**
     * Working days from $from through $today (inclusive, clamped to the
     * current week's Monday) with no submitted entry — computed the same
     * way JournalCalendarController derives a day's status, since
     * journal_entries.status is only ever stored as draft/submitted (never
     * a "missing" row), so it must be derived, not queried directly.
     */
    private function countMissingWorkingDays(int $studentId, int $batchId, CarbonInterface $rangeStart, CarbonInterface $today, int $workingDaysPerWeek): int
    {
        $weekStart = now()->startOfWeek();
        $cursor = $weekStart->greaterThan($rangeStart) ? $weekStart : $rangeStart->copy();

        if ($cursor->gt($today)) {
            return 0;
        }

        $submittedDates = JournalEntry::where('student_id', $studentId)
            ->where('batch_id', $batchId)
            ->where('status', 'submitted')
            ->whereDate('entry_date', '>=', $cursor->toDateString())
            ->whereDate('entry_date', '<=', $today->toDateString())
            ->pluck('entry_date')
            ->map(fn ($date) => $date->toDateString())
            ->all();

        $missing = 0;

        while ($cursor->lte($today)) {
            if (BatchWorkingDays::isWorkingDay($cursor, $workingDaysPerWeek) && ! in_array($cursor->toDateString(), $submittedDates, true)) {
                $missing++;
            }

            $cursor = $cursor->copy()->addDay();
        }

        return $missing;
    }

    private function toneForAction(string $action): string
    {
        $action = strtolower($action);

        return match (true) {
            str_contains($action, 'approve') => 'green',
            str_contains($action, 'return') => 'amber',
            str_contains($action, 'submit') => 'blue',
            default => 'slate',
        };
    }
}
