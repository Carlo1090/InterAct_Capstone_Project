<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Concerns\BuildsExitInterviewPdf;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Student\Concerns\ResolvesStudentEnrollment;
use App\Http\Requests\Student\StoreExitInterviewRequest;
use App\Models\BatchStudent;
use App\Models\Notification;
use App\Models\StudentExitInterview;
use App\Models\SystemLog;
use App\Services\DtrService;
use App\Support\ExitInterviewFormLayout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The student's own copy of the CABM "Internship Program Student Exit
 * Interview Form" — the last thing an intern fills in, at the close of the
 * placement.
 *
 * DELIBERATE DEVIATION FROM THE PROJECT-WIDE WRITE RULE, and the only one on
 * any student surface: every other student WRITE endpoint requires
 * `activeEnrollment()`, so a `completed` student is read-only. This form is
 * resolved through `currentEnrollment()` (active OR completed) instead,
 * because an exit interview is BY DEFINITION filed at or after the end of the
 * placement — applying the usual rule would make the form unwritable at
 * exactly the moment it falls due, which is the whole reason it exists.
 * A `dropped` student still gets nothing, as everywhere else.
 *
 * Once SUBMITTED the student's half locks (there is no unsubmit); the
 * coordinator's own section stays theirs to fill afterwards.
 */
class StudentExitInterviewController extends Controller
{
    use BuildsExitInterviewPdf;
    use ResolvesStudentEnrollment;

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $enrollment = $this->currentEnrollment($user->id);

        abort_if($enrollment === null, 422, 'You are not enrolled in an OJT batch, so there is no internship to exit from yet.');

        $interview = $this->find($user->id, $enrollment->batch_id);

        return response()->json([
            'interview' => $interview ? $this->payload($interview) : null,
            'header' => $this->readOnlyHeader($enrollment),
            'suggested_total_hours' => $this->suggestedTotalHours($enrollment),
            // Per QUESTION, since question 7 has four printed lines and
            // every other has five. The SPA sizes its counters from this.
            'answer_char_limits' => ExitInterviewFormLayout::charLimits(),
            'answer_char_limit' => ExitInterviewFormLayout::ANSWER_CHAR_LIMIT,
            // The placement has to be over (or all but over) before an exit
            // interview means anything, but the student is warned rather than
            // blocked: a coordinator may well ask for it on the last week.
            'ojt_completed' => $enrollment->status === 'completed',
        ]);
    }

    public function store(StoreExitInterviewRequest $request): JsonResponse
    {
        $user = $request->user();
        $enrollment = $this->currentEnrollment($user->id);

        abort_if($enrollment === null, 422, 'You are not enrolled in an OJT batch, so there is no internship to exit from yet.');

        $interview = $this->find($user->id, $enrollment->batch_id);

        abort_if(
            $interview && $interview->submission_status !== 'draft',
            422,
            'Your exit interview has already been submitted and can no longer be edited.'
        );

        $validated = $request->validated();
        $submitting = $request->boolean('submit');

        $interview = StudentExitInterview::updateOrCreate(
            ['student_id' => $user->id, 'batch_id' => $enrollment->batch_id],
            [
                'student_info' => $validated['student_info'] ?? [],
                'responses' => $validated['responses'] ?? [],
                'submission_status' => $submitting ? 'submitted' : 'draft',
                'submitted_at' => $submitting ? now() : null,
            ]
        );

        if ($submitting) {
            $this->notifyCoordinator($enrollment, $user->name);
            SystemLog::record('Exit Interview Submitted', $user->name.' submitted their internship exit interview.');
        }

        return response()->json([
            'interview' => $this->payload($interview),
            'message' => $submitting
                ? 'Your exit interview has been submitted to your coordinator.'
                : 'Draft saved.',
        ]);
    }

    public function pdf(Request $request): Response
    {
        $user = $request->user();
        $enrollment = $this->currentEnrollment($user->id);

        abort_if($enrollment === null, 422, 'You are not enrolled in an OJT batch.');

        $interview = $this->find($user->id, $enrollment->batch_id);

        abort_if($interview === null, 404, 'You have not started your exit interview yet.');

        return $this->renderExitInterviewPdf($interview);
    }

    private function find(int $studentId, int $batchId): ?StudentExitInterview
    {
        return StudentExitInterview::with('student')
            ->where('student_id', $studentId)
            ->where('batch_id', $batchId)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(StudentExitInterview $interview): array
    {
        return [
            'id' => $interview->id,
            'submission_status' => $interview->submission_status,
            'submitted_at' => $interview->submitted_at?->toIso8601String(),
            'reviewed_at' => $interview->reviewed_at?->toIso8601String(),
            'student_info' => $interview->student_info ?? [],
            'responses' => $interview->responses ?? [],
        ];
    }

    /**
     * Section A's derived half — everything the paper form asks for that the
     * system already knows, so the student types only what it cannot.
     *
     * @return array<string, mixed>
     */
    private function readOnlyHeader(BatchStudent $enrollment): array
    {
        $enrollment->loadMissing(['batch.coordinator:id,name', 'company:id,name', 'student.program']);

        $range = $this->ojtRange($enrollment);
        $student = $enrollment->student;

        return [
            'student_name' => $student?->name,
            'program' => $student?->program?->code ?? $student?->program?->name,
            'company' => $enrollment->company?->name,
            'training_period' => $range['start']->format('M j, Y').' - '.$range['end']->format('M j, Y'),
            'coordinator_name' => $enrollment->batch?->coordinator?->name,
            // A sensible starting point for "Department/Position Assigned":
            // the division the coordinator recorded on the roster. Still
            // typed, since the paper form asks for something more specific.
            'assigned_division' => $enrollment->assigned_division,
        ];
    }

    /**
     * The banked DTR hours, offered as a prefill where the coordinator runs
     * the Daily Time Record. Advisory only — the student's typed value always
     * wins, exactly as on the Weekly Activity Log, because a forgotten
     * clock-out must stay correctable before a supervisor signs the paper.
     */
    private function suggestedTotalHours(BatchStudent $enrollment): ?float
    {
        if (! $enrollment->batch?->coordinator?->dtr_enabled) {
            return null;
        }

        $minutes = app(DtrService::class)->minutesCompleted($enrollment->student_id, $enrollment->batch_id);

        return $minutes > 0 ? round($minutes / 60, 1) : null;
    }

    /**
     * The batch's own coordinator is notified, not every coordinator in the
     * department — `batches.coordinator_id` is the single unambiguous owner,
     * the same call the info sheet's submission notification makes.
     */
    private function notifyCoordinator(BatchStudent $enrollment, string $studentName): void
    {
        $coordinatorId = $enrollment->batch?->coordinator_id;

        if (! $coordinatorId) {
            return;
        }

        Notification::create([
            'user_id' => $coordinatorId,
            // notifications.type is a strict DB enum: email / push / in_app.
            'type' => 'in_app',
            'title' => 'Exit interview submitted',
            'message' => $studentName.' submitted their internship exit interview.',
            'sent_at' => now(),
        ]);
    }
}
