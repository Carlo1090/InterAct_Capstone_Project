<?php

namespace App\Http\Controllers\Supervisor;

use App\Http\Controllers\Concerns\ReviewsWeeklyJournals;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Supervisor\Concerns\ScopesSupervisorWork;
use App\Http\Requests\Supervisor\ReturnWeeklyLogRequest;
use App\Models\User;
use App\Models\WeeklyLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The company supervisor's review of weekly narrative journals.
 *
 * Everything about WHAT reviewing means lives in ReviewsWeeklyJournals, shared
 * with the coordinator's own review surface for coordinator-centered batches.
 * This controller supplies only the scope: the interns of the companies this
 * login represents.
 */
class SupervisorJournalController extends Controller
{
    use ReviewsWeeklyJournals;
    use ScopesSupervisorWork;

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

        return response()->json([
            'status' => $status,
            'logs' => $this->weeklyLogRows($logs),
        ]);
    }

    /**
     * One weekly log (own-scope only) with narrative + that week's daily entries.
     */
    public function show(Request $request, WeeklyLog $weeklyLog): JsonResponse
    {
        $this->authorizeLog($request->user(), $weeklyLog);

        return response()->json($this->weeklyLogPayload($weeklyLog));
    }

    public function pdf(Request $request, WeeklyLog $weeklyLog): Response
    {
        $this->authorizeLog($request->user(), $weeklyLog);

        return $this->weeklyLogPdfResponse($weeklyLog, $request->user()->name);
    }

    /**
     * Approve a submitted, still-pending/returned weekly log.
     */
    public function approve(Request $request, WeeklyLog $weeklyLog): JsonResponse
    {
        $this->authorizeLog($request->user(), $weeklyLog);

        return response()->json($this->approveWeeklyLog($weeklyLog, $request->user()));
    }

    /**
     * Return a submitted log to the student with a required explanatory comment.
     */
    public function returnLog(ReturnWeeklyLogRequest $request, WeeklyLog $weeklyLog): JsonResponse
    {
        $this->authorizeLog($request->user(), $weeklyLog);

        return response()->json($this->returnWeeklyLog(
            $weeklyLog,
            $request->user(),
            $request->validated()['supervisor_comment'],
        ));
    }

    /**
     * One intern's whole weekly-journal notebook, reached from the Journals
     * action on each My Interns row.
     */
    public function notebook(Request $request, User $student): JsonResponse
    {
        abort_unless($student->role === 'student', 404);

        $supervisor = $request->user();
        abort_unless(
            $this->supervisedStudentIds($supervisor)->contains($student->id),
            403,
            'This student is not one of your interns.'
        );

        $enrollment = $this->supervisedEnrollments($supervisor)
            ->where('student_id', $student->id)
            ->with(['batch:id,name', 'company:id,name'])
            ->latest('id')
            ->first();

        return response()->json($this->notebookPayload($student, $enrollment));
    }
}
