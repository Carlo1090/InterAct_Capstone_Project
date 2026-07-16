<?php

namespace App\Http\Controllers\Supervisor;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Supervisor\Concerns\ScopesSupervisorWork;
use App\Http\Requests\Supervisor\ReturnWeeklyLogRequest;
use App\Models\BatchStudent;
use App\Models\JournalEntry;
use App\Models\SystemLog;
use App\Models\WeeklyLog;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SupervisorJournalController extends Controller
{
    use ScopesSupervisorWork;

    private const REVIEWABLE_STATUSES = ['pending', 'returned'];

    /**
     * Submitted weekly narrative logs of this supervisor's interns, filterable
     * by status (default pending). Drafts (never submitted) are excluded.
     */
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');
        $status = in_array($status, ['pending', 'approved', 'returned'], true) ? $status : 'pending';

        $studentIds = $this->supervisedStudentIds($request->user());

        $logs = WeeklyLog::whereIn('student_id', $studentIds)
            ->whereNotNull('submitted_at')
            ->where('status', $status)
            ->with('student:id,name,student_id_number')
            ->orderByDesc('submitted_at')
            ->get();

        // Daily-entry counts per week for the listed students (single query).
        $entries = JournalEntry::whereIn('student_id', $logs->pluck('student_id')->unique())
            ->get(['student_id', 'entry_date']);

        $rows = $logs->map(function (WeeklyLog $log) use ($entries) {
            $count = $entries
                ->where('student_id', $log->student_id)
                ->filter(fn (JournalEntry $entry) => $entry->entry_date->between($log->week_start, $log->week_end))
                ->count();

            return [
                'id' => $log->id,
                'student_id' => $log->student_id,
                'student_name' => $log->student?->name ?? '',
                'student_id_number' => $log->student?->student_id_number,
                'week_start' => $log->week_start->toDateString(),
                'week_end' => $log->week_end->toDateString(),
                'status' => $log->status,
                'submitted_at' => $log->submitted_at?->toIso8601String(),
                'entries_count' => $count,
            ];
        });

        return response()->json([
            'status' => $status,
            'logs' => $rows,
        ]);
    }

    /**
     * One weekly log (own-scope only) with narrative + that week's daily entries.
     */
    public function show(Request $request, WeeklyLog $weeklyLog): JsonResponse
    {
        $this->authorizeLog($request->user(), $weeklyLog);

        $dailyEntries = JournalEntry::where('student_id', $weeklyLog->student_id)
            ->whereBetween('entry_date', [$weeklyLog->week_start->toDateString(), $weeklyLog->week_end->toDateString()])
            ->orderBy('entry_date')
            ->get(['entry_date', 'status', 'content']);

        $weeklyLog->load('student:id,name,student_id_number');

        return response()->json([
            'id' => $weeklyLog->id,
            'student' => [
                'id' => $weeklyLog->student_id,
                'name' => $weeklyLog->student?->name ?? '',
                'student_id_number' => $weeklyLog->student?->student_id_number,
            ],
            'week_start' => $weeklyLog->week_start->toDateString(),
            'week_end' => $weeklyLog->week_end->toDateString(),
            'status' => $weeklyLog->status,
            'supervisor_comment' => $weeklyLog->supervisor_comment,
            'narrative' => $weeklyLog->narrative ?? '',
            'submitted_at' => $weeklyLog->submitted_at?->toIso8601String(),
            'reviewed_at' => $weeklyLog->reviewed_at?->toIso8601String(),
            'reviewable' => $this->isReviewable($weeklyLog),
            'daily_entries' => $dailyEntries,
        ]);
    }

    /**
     * Same document family as the student's own weekly-log PDF and the
     * daily journal entry PDF — reuses pdf.weekly-log so a supervisor's
     * downloaded copy looks like the same kind of document they reviewed
     * on screen.
     */
    public function pdf(Request $request, WeeklyLog $weeklyLog): Response
    {
        $this->authorizeLog($request->user(), $weeklyLog);

        $weeklyLog->load('student.program');

        $enrollment = BatchStudent::where('batch_id', $weeklyLog->batch_id)
            ->where('student_id', $weeklyLog->student_id)
            ->with('company:id,name')
            ->first();

        // Same "Week N" numbering as the student's own PDF: 1-based position
        // among that student's WeeklyLogs ordered by week_start ascending.
        $weekNumber = WeeklyLog::where('student_id', $weeklyLog->student_id)
            ->whereDate('week_start', '<', $weeklyLog->week_start->toDateString())
            ->count() + 1;

        $pdf = Pdf::loadView('pdf.weekly-log', [
            'narrative' => $weeklyLog->narrative ?? '',
            'weekNumber' => $weekNumber,
            'header' => [
                'student_name' => $weeklyLog->student?->name ?? '',
                'program' => $weeklyLog->student?->program?->name,
                'company_name' => $enrollment?->company?->name,
                'supervisor_name' => $request->user()->name,
            ],
        ]);

        return $pdf->download("weekly-log-{$weeklyLog->id}.pdf");
    }

    /**
     * Approve a submitted, still-pending/returned weekly log.
     */
    public function approve(Request $request, WeeklyLog $weeklyLog): JsonResponse
    {
        $this->authorizeLog($request->user(), $weeklyLog);
        $this->assertReviewable($weeklyLog);

        $weeklyLog->update([
            'status' => 'approved',
            'supervisor_id' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        $weeklyLog->loadMissing('student:id,name');
        SystemLog::record('Weekly Journal Approved', "Approved {$weeklyLog->student?->name}'s week of {$weeklyLog->week_start->toDateString()}");

        return response()->json($weeklyLog->fresh());
    }

    /**
     * Return a submitted log to the student with a required explanatory comment.
     */
    public function returnLog(ReturnWeeklyLogRequest $request, WeeklyLog $weeklyLog): JsonResponse
    {
        $this->authorizeLog($request->user(), $weeklyLog);
        $this->assertReviewable($weeklyLog);

        $weeklyLog->update([
            'status' => 'returned',
            'supervisor_comment' => $request->validated()['supervisor_comment'],
            'supervisor_id' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        $weeklyLog->loadMissing('student:id,name');
        SystemLog::record('Weekly Journal Returned', "Returned {$weeklyLog->student?->name}'s week of {$weeklyLog->week_start->toDateString()}");

        return response()->json($weeklyLog->fresh());
    }

    private function isReviewable(WeeklyLog $log): bool
    {
        return $log->submitted_at !== null && in_array($log->status, self::REVIEWABLE_STATUSES, true);
    }

    private function assertReviewable(WeeklyLog $log): void
    {
        abort_if($log->submitted_at === null, 422, 'This weekly log has not been submitted yet.');
        abort_unless(
            in_array($log->status, self::REVIEWABLE_STATUSES, true),
            422,
            'This weekly log has already been finalized.'
        );
    }
}
