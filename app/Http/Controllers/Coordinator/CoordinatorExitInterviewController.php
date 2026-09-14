<?php

namespace App\Http\Controllers\Coordinator;

use App\Http\Controllers\Concerns\BuildsExitInterviewPdf;
use App\Http\Controllers\Controller;
use App\Http\Requests\Coordinator\SaveExitInterviewReviewRequest;
use App\Models\Batch;
use App\Models\Program;
use App\Models\StudentExitInterview;
use App\Models\SystemLog;
use App\Support\ExitInterviewFormLayout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

/**
 * The coordinator's window onto their students' exit interviews: read every
 * answer, download the official PDF, and fill the one part of the form that is
 * theirs — the "SECTION FOR OJT/INTERNSHIP COORDINATOR" block at the foot of
 * page 2.
 *
 * There is deliberately NO accept/reject here, unlike the Student Info Sheets
 * queue. An exit interview is feedback, not an application: it gates nothing
 * and a coordinator disagreeing with an answer is not grounds to bounce it
 * back. The same read-mostly posture as CoordinatorWeeklyActivityLogController.
 *
 * Scope is the batch's program via User::coordinatorProgramIds(), like every
 * other coordinator surface; out-of-scope 403s.
 */
class CoordinatorExitInterviewController extends Controller
{
    use BuildsExitInterviewPdf;

    /**
     * Every in-scope student's exit interview, most recently submitted first.
     *
     * Drafts are INCLUDED, and that is on purpose: this list is also how a
     * coordinator sees who has started and who has not, and there is nothing
     * to hide — unlike the weekly-journal queue, whose drafts are a nightly
     * job's by-product rather than a student's own act.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'program_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:draft,submitted,reviewed'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        [$scopedProgramIds, $programIds] = $this->resolveScope($request, isset($validated['program_id']) && $validated['program_id'] !== null ? (int) $validated['program_id'] : null);

        $interviews = StudentExitInterview::whereHas('batch', fn ($query) => $query->whereIn('program_id', $programIds))
            ->when(
                $validated['status'] ?? null,
                fn ($query, $status) => $query->where('submission_status', $status)
            )
            ->when(
                $validated['search'] ?? null,
                fn ($query, $search) => $query->whereHas('student', fn ($q) => $q->where('name', 'like', '%'.$search.'%'))
            )
            ->with(['student:id,name,student_id_number', 'batch.program:id,code,name'])
            ->orderByRaw('submitted_at is null')
            ->orderByDesc('submitted_at')
            ->orderBy('student_id')
            ->paginate(20)
            ->withQueryString();

        $interviews->through(function (StudentExitInterview $interview) {
            $program = $interview->batch?->program;
            $section = $interview->coordinator_section ?? [];

            return [
                'id' => $interview->id,
                'student_id' => $interview->student_id,
                'student_name' => $interview->student?->name ?? '',
                'student_id_number' => $interview->student?->student_id_number,
                'program' => $program?->code ?? $program?->name ?? '',
                'submission_status' => $interview->submission_status,
                'submitted_at' => $interview->submitted_at?->toIso8601String(),
                'reviewed_at' => $interview->reviewed_at?->toIso8601String(),
                'compliance' => $section['compliance'] ?? null,
            ];
        });

        return response()->json([
            'programs' => Program::whereIn('id', $scopedProgramIds)->orderBy('name')->get(['id', 'name', 'code']),
            'interviews' => $interviews,
        ]);
    }

    /**
     * One in-scope interview, shaped so the SPA can render the whole paper
     * form read-only beside the coordinator's own editable block.
     */
    public function show(Request $request, StudentExitInterview $exitInterview): JsonResponse
    {
        $this->assertInScope($request, $exitInterview);

        return response()->json([
            'id' => $exitInterview->id,
            'submission_status' => $exitInterview->submission_status,
            'submitted_at' => $exitInterview->submitted_at?->toIso8601String(),
            'reviewed_at' => $exitInterview->reviewed_at?->toIso8601String(),
            'reviewed_by' => $exitInterview->reviewer?->name,
            'header' => $this->exitInterviewHeader($exitInterview),
            'responses' => $exitInterview->responses ?? [],
            'coordinator_section' => $exitInterview->coordinator_section ?? [],
        ]);
    }

    /**
     * Save the coordinator's own block. Never touches `responses`, and never
     * moves the student's `submission_status` backwards — a saved review
     * stamps `reviewed`, and re-saving an already-reviewed interview just
     * updates it.
     */
    public function update(SaveExitInterviewReviewRequest $request, StudentExitInterview $exitInterview): JsonResponse
    {
        $this->assertInScope($request, $exitInterview);

        abort_if(
            $exitInterview->submission_status === 'draft',
            422,
            'This student has not submitted their exit interview yet.'
        );

        $validated = $request->validated();

        $exitInterview->update([
            'coordinator_section' => [
                'compliance' => $validated['compliance'] ?? null,
                'pending_detail' => $validated['pending_detail'] ?? null,
                'remarks' => $validated['remarks'] ?? null,
            ],
            'submission_status' => 'reviewed',
            'reviewed_at' => now(),
            'reviewed_by' => $request->user()->id,
        ]);

        $exitInterview->loadMissing('student:id,name');

        SystemLog::record(
            'Exit Interview Reviewed',
            'Recorded compliance verification on '.($exitInterview->student?->name ?? 'a student').'\'s exit interview.'
        );

        return response()->json([
            'coordinator_section' => $exitInterview->coordinator_section,
            'submission_status' => $exitInterview->submission_status,
            'reviewed_at' => $exitInterview->reviewed_at?->toIso8601String(),
            'message' => 'Coordinator section saved.',
        ]);
    }

    /**
     * The same measured facsimile the student downloads — one renderer, so the
     * coordinator's filed copy and the student's printed copy cannot differ.
     */
    public function pdf(Request $request, StudentExitInterview $exitInterview): Response
    {
        $this->assertInScope($request, $exitInterview);

        $exitInterview->loadMissing('student:id,name');

        $slug = str($exitInterview->student?->name ?? (string) $exitInterview->student_id)->slug();

        return $this->renderExitInterviewPdf($exitInterview, "exit-interview-{$slug}.pdf");
    }

    /**
     * The aggregate Summary Report on Student Exit Interview — every
     * in-scope intern's answer to question 1 gathered together, then
     * question 2, and so on, instead of one row per student. Hard Rule #4
     * used to keep this whole document out of scope; narrowed 2026-09-10 at
     * the project owner's request. Reached as a tab on the same
     * Student Exit Interviews page, not a separate nav item.
     *
     * Deliberately NOT curated like the Annual SIPP / HTE / Group Info Sheet
     * reports: those exist so a coordinator can correct messy real-world
     * data before filing an official annex. There is nothing to correct
     * here — every answer is text the student already submitted themselves
     * — so this is a live read, recomputed on every request, with nothing
     * persisted.
     *
     * DRAFTS ARE EXCLUDED. A draft can be blank or half-typed, and only a
     * submitted interview is guaranteed to carry all fourteen answers
     * (StoreExitInterviewRequest enforces that on submit) — including a
     * draft would let an empty in-progress form skew what looks like a
     * completed tally.
     */
    public function summary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'program_id' => ['nullable', 'integer'],
            'academic_year' => ['nullable', 'string', 'max:20'],
        ]);

        [$scopedProgramIds, $programIds] = $this->resolveScope($request, isset($validated['program_id']) && $validated['program_id'] !== null ? (int) $validated['program_id'] : null);

        $academicYears = Batch::whereIn('program_id', $scopedProgramIds)
            ->distinct()
            ->orderByDesc('academic_year')
            ->pluck('academic_year')
            ->values();

        $academicYear = $validated['academic_year'] ?? $academicYears->first();

        $interviews = StudentExitInterview::whereIn('submission_status', ['submitted', 'reviewed'])
            ->whereHas('batch', function ($query) use ($programIds, $academicYear) {
                $query->whereIn('program_id', $programIds);

                if ($academicYear) {
                    $query->where('academic_year', $academicYear);
                }
            })
            ->with(['student:id,name,student_id_number', 'batch.program:id,code,name'])
            ->orderBy('student_id')
            ->get();

        $questions = [];

        foreach (ExitInterviewFormLayout::SECTIONS as $section) {
            foreach ($section['questions'] as $question) {
                $choiceKey = $question['choice'] ?? null;
                $tally = $choiceKey ? ['yes' => 0, 'no' => 0, 'unanswered' => 0] : null;
                $answers = [];

                foreach ($interviews as $interview) {
                    $responses = $interview->responses ?? [];
                    $text = trim((string) ($responses[$question['key']] ?? ''));
                    $choice = $choiceKey ? ($responses[$choiceKey] ?? null) : null;

                    if ($choiceKey) {
                        $tally[in_array($choice, ['yes', 'no'], true) ? $choice : 'unanswered']++;
                    }

                    if ($text === '' && $choice === null) {
                        continue;
                    }

                    $program = $interview->batch?->program;

                    $answers[] = [
                        'student_id' => $interview->student_id,
                        'student_name' => $interview->student?->name ?? '',
                        'student_id_number' => $interview->student?->student_id_number,
                        'program' => $program?->code ?? $program?->name ?? '',
                        'choice' => $choice,
                        'text' => $text,
                    ];
                }

                $questions[] = [
                    'key' => $question['key'],
                    'number' => $question['n'],
                    'section' => $section['heading'],
                    'text' => $question['text'],
                    'choice_key' => $choiceKey,
                    'tally' => $tally,
                    'answers' => $answers,
                ];
            }
        }

        return response()->json([
            'programs' => Program::whereIn('id', $scopedProgramIds)->orderBy('name')->get(['id', 'name', 'code']),
            'academic_years' => $academicYears,
            'academic_year' => $academicYear,
            'total_respondents' => $interviews->count(),
            'questions' => $questions,
        ]);
    }

    /**
     * Shared by index() and summary(): the coordinator's own department-wide
     * scope, optionally narrowed to one requested program (403 if it is
     * outside that scope).
     *
     * @return array{0: Collection<int, int>, 1: Collection<int, int>}
     */
    private function resolveScope(Request $request, ?int $requestedProgramId): array
    {
        $scopedProgramIds = $request->user()->coordinatorProgramIds();
        $programIds = $scopedProgramIds;

        if ($requestedProgramId !== null) {
            abort_unless($scopedProgramIds->contains($requestedProgramId), 403, 'That program is outside your assigned department(s).');
            $programIds = collect([$requestedProgramId]);
        }

        return [$scopedProgramIds, $programIds];
    }

    private function assertInScope(Request $request, StudentExitInterview $interview): void
    {
        $interview->loadMissing('batch:id,program_id');

        abort_unless(
            $interview->batch && $request->user()->coordinatorProgramIds()->contains($interview->batch->program_id),
            403,
            'This exit interview is not in your scope.'
        );
    }
}
