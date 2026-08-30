<?php

namespace App\Http\Controllers\Coordinator;

use App\Http\Controllers\Concerns\ReviewsWeeklyJournals;
use App\Http\Controllers\Controller;
use App\Http\Requests\Coordinator\ReturnWeeklyLogRequest;
use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\User;
use App\Models\WeeklyLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

/**
 * The coordinator's OWN review of weekly narrative journals — the queue, the
 * per-intern notebook, and the two verdicts — for batches running under the
 * `coordinator` OJT type, where there is no company supervisor to give them.
 *
 * This is deliberately NOT the same surface as CoordinatorWeeklyJournalController,
 * which stays exactly as it was: read-only monitoring across EVERY batch in
 * scope. That page answers "how is my department doing?"; this one answers
 * "what is waiting on me?" and is the only coordinator surface that writes a
 * verdict. Keeping them apart is what stops a coordinator gaining approve /
 * return over supervisor-supported batches, where the verdict belongs to the
 * company — the project's standing rule that review verdicts belong to
 * whoever actually supervised the work.
 *
 * Everything about WHAT reviewing means is shared with the supervisor's
 * controller through ReviewsWeeklyJournals, so the two can never disagree
 * about what "reviewable" means or what a verdict writes.
 *
 * SCOPE is `coordinatorProgramIds()` — every program in the coordinator's
 * department — matching every other coordinator page rather than narrowing to
 * `batches.coordinator_id`. A department's coordinators already see and act on
 * each other's batches everywhere else, and a cohort whose coordinator is away
 * must not have its journals stuck with nobody able to sign them off.
 */
class CoordinatorJournalReviewController extends Controller
{
    use ReviewsWeeklyJournals;

    /**
     * Enrollments this coordinator may review: in their program scope, and on a
     * batch that actually runs coordinator-centered.
     */
    private function reviewableEnrollments(User $coordinator): Builder
    {
        return BatchStudent::whereHas(
            'batch',
            fn (Builder $query) => $query
                ->whereIn('program_id', $coordinator->coordinatorProgramIds())
                ->where('ojt_type', Batch::OJT_TYPE_COORDINATOR)
        );
    }

    /**
     * @return Collection<int, int>
     */
    private function reviewableStudentIds(User $coordinator): Collection
    {
        return $this->reviewableEnrollments($coordinator)
            ->pluck('student_id')
            ->unique()
            ->values();
    }

    /**
     * 403 unless the log belongs to a coordinator-centered batch in scope.
     *
     * Keyed off the LOG'S OWN batch rather than the student, because a student
     * may have been in a supervisor-supported cohort previously — those weeks
     * were the company's to review and stay that way.
     */
    private function authorizeReview(User $coordinator, WeeklyLog $weeklyLog): void
    {
        $weeklyLog->loadMissing('batch');

        abort_unless(
            $weeklyLog->batch
                && $weeklyLog->batch->isCoordinatorCentered()
                && $coordinator->coordinatorProgramIds()->contains($weeklyLog->batch->program_id),
            403,
            'This weekly journal is not one you review.'
        );
    }

    /**
     * The review queue: submitted weekly logs on coordinator-centered batches,
     * one status at a time (default pending), most recently submitted first.
     * Never-submitted drafts are excluded, matching every other review surface.
     */
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');
        $status = in_array($status, ['pending', 'approved', 'returned'], true) ? $status : 'pending';

        $coordinator = $request->user();

        $logs = WeeklyLog::whereIn('student_id', $this->reviewableStudentIds($coordinator))
            ->whereHas(
                'batch',
                fn (Builder $query) => $query
                    ->whereIn('program_id', $coordinator->coordinatorProgramIds())
                    ->where('ojt_type', Batch::OJT_TYPE_COORDINATOR)
            )
            ->whereNotNull('submitted_at')
            ->where('status', $status)
            ->with(['student:id,name,student_id_number', 'batch:id,name'])
            ->orderByDesc('submitted_at')
            ->get();

        $rows = $this->weeklyLogRows($logs)->map(function (array $row) use ($logs) {
            $row['batch'] = $logs->firstWhere('id', $row['id'])?->batch?->name ?? '';

            return $row;
        });

        return response()->json([
            'status' => $status,
            'logs' => $rows->values(),
            'counts' => $this->statusCounts($coordinator),
            // Lets the page tell 'you have no coordinator-centered batches'
            // apart from 'you have one, nobody is enrolled yet'. Without it the
            // empty state told a coordinator staring at their own
            // coordinator-centered batch that they had not made one.
            'centered_batches' => Batch::whereIn('program_id', $coordinator->coordinatorProgramIds())
                ->where('ojt_type', Batch::OJT_TYPE_COORDINATOR)
                ->count(),
        ]);
    }

    /**
     * Tallies for the queue's three tabs, in ONE grouped query rather than
     * three round trips — the same reasoning as DtrMonitorController's
     * sessionTallies().
     *
     * @return array<string, int>
     */
    private function statusCounts(User $coordinator): array
    {
        $counts = WeeklyLog::whereIn('student_id', $this->reviewableStudentIds($coordinator))
            ->whereHas(
                'batch',
                fn (Builder $query) => $query
                    ->whereIn('program_id', $coordinator->coordinatorProgramIds())
                    ->where('ojt_type', Batch::OJT_TYPE_COORDINATOR)
            )
            ->whereNotNull('submitted_at')
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'pending' => (int) ($counts['pending'] ?? 0),
            'approved' => (int) ($counts['approved'] ?? 0),
            'returned' => (int) ($counts['returned'] ?? 0),
        ];
    }

    /**
     * Every intern on a coordinator-centered batch in scope, with their own
     * tallies — the index into the notebooks.
     *
     * Without this an intern with nothing currently pending would be
     * unreachable: the queue only ever lists one status at a time, so "read
     * this student's whole placement" would depend on them happening to have a
     * week in whichever tab is open.
     */
    public function interns(Request $request): JsonResponse
    {
        $coordinator = $request->user();

        $enrollments = $this->reviewableEnrollments($coordinator)
            // NOT avatar_url — that is an accessor over `avatar_path`, not a
            // column, and naming it in a column list is a hard SQL error.
            ->with(['student:id,name,student_id_number', 'batch:id,name', 'company:id,name'])
            ->get();

        $tallies = WeeklyLog::whereIn('student_id', $enrollments->pluck('student_id')->unique())
            ->whereNotNull('submitted_at')
            ->selectRaw('student_id, status, COUNT(*) as aggregate')
            ->groupBy('student_id', 'status')
            ->get();

        $rows = $enrollments
            ->sortBy(fn (BatchStudent $enrollment) => $enrollment->student?->name ?? '')
            ->map(function (BatchStudent $enrollment) use ($tallies) {
                $mine = $tallies->where('student_id', $enrollment->student_id);

                return [
                    'student_id' => $enrollment->student_id,
                    'student_name' => $enrollment->student?->name ?? '',
                    'student_id_number' => $enrollment->student?->student_id_number,
                    'batch' => $enrollment->batch?->name ?? '',
                    'company' => $enrollment->company?->name ?? '',
                    'enrollment_status' => $enrollment->status,
                    'pending' => (int) ($mine->firstWhere('status', 'pending')->aggregate ?? 0),
                    'approved' => (int) ($mine->firstWhere('status', 'approved')->aggregate ?? 0),
                    'returned' => (int) ($mine->firstWhere('status', 'returned')->aggregate ?? 0),
                ];
            });

        return response()->json(['interns' => $rows->values()]);
    }

    public function show(Request $request, WeeklyLog $weeklyLog): JsonResponse
    {
        $this->authorizeReview($request->user(), $weeklyLog);

        return response()->json($this->weeklyLogPayload($weeklyLog));
    }

    public function pdf(Request $request, WeeklyLog $weeklyLog): Response
    {
        $this->authorizeReview($request->user(), $weeklyLog);

        return $this->weeklyLogPdfResponse($weeklyLog, $request->user()->name);
    }

    public function approve(Request $request, WeeklyLog $weeklyLog): JsonResponse
    {
        $this->authorizeReview($request->user(), $weeklyLog);

        return response()->json($this->approveWeeklyLog($weeklyLog, $request->user()));
    }

    public function returnLog(ReturnWeeklyLogRequest $request, WeeklyLog $weeklyLog): JsonResponse
    {
        $this->authorizeReview($request->user(), $weeklyLog);

        return response()->json($this->returnWeeklyLog(
            $weeklyLog,
            $request->user(),
            $request->validated()['supervisor_comment'],
        ));
    }

    /**
     * One intern's whole notebook — every week they have handed in, oldest
     * first, with the same Approve / Return actions the queue offers.
     *
     * The supervisor has had this since the per-intern notebook was built; a
     * coordinator running a coordinator-centered cohort is the reviewer, so
     * they get the identical surface rather than the queue's one-status slice.
     * Same shared builder, so the two notebooks cannot drift.
     */
    public function notebook(Request $request, User $student): JsonResponse
    {
        abort_unless($student->role === 'student', 404);

        $coordinator = $request->user();

        $enrollment = $this->reviewableEnrollments($coordinator)
            ->where('student_id', $student->id)
            ->with(['batch:id,name', 'company:id,name'])
            ->latest('id')
            ->first();

        abort_unless(
            $enrollment !== null,
            403,
            'This student is not on one of your coordinator-centered batches.'
        );

        return response()->json($this->notebookPayload($student, $enrollment));
    }
}
