<?php

namespace App\Http\Controllers\Coordinator;

use App\Http\Controllers\Controller;
use App\Models\BatchStudent;
use App\Models\JournalEntry;
use App\Models\Program;
use App\Models\WeeklyLog;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Read-only coordinator view over students' weekly narrative journals.
 * Review verdicts (approve/return) belong to supervisors — the coordinator
 * observes; there are deliberately no write actions here.
 */
class CoordinatorWeeklyJournalController extends Controller
{
    /**
     * Submitted weekly logs of students whose batch program is in the
     * coordinator's scope. Drafts (never submitted) are excluded, same as
     * the supervisor's review queue. Filters: program_id (must be in
     * scope), status (pending|approved|returned), from/to (Y-m-d, matched
     * against week_start).
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'program_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:pending,approved,returned'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $scopedProgramIds = $request->user()->coordinatorProgramIds();
        $programIds = $scopedProgramIds;

        if (! empty($validated['program_id'])) {
            $requestedProgramId = (int) $validated['program_id'];
            abort_unless($scopedProgramIds->contains($requestedProgramId), 403, 'That program is outside your assigned department(s).');
            $programIds = collect([$requestedProgramId]);
        }

        $from = $validated['from'] ?? null;
        $to = $validated['to'] ?? null;

        // Tolerate a reversed range rather than erroring.
        if ($from && $to && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        // The coordinator's full program scope — powers the Program filter
        // dropdown regardless of which program is currently selected.
        $programs = Program::whereIn('id', $scopedProgramIds)
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        $logs = WeeklyLog::whereHas('batch', fn ($query) => $query->whereIn('program_id', $programIds))
            ->whereNotNull('submitted_at')
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($from, fn ($query) => $query->whereDate('week_start', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('week_start', '<=', $to))
            ->with(['student:id,name,student_id_number', 'batch.program:id,code,name'])
            ->orderByDesc('submitted_at')
            ->paginate(20);

        $logs->through(function (WeeklyLog $log) {
            $program = $log->batch?->program;

            return [
                'id' => $log->id,
                'student_id' => $log->student_id,
                'student_name' => $log->student?->name ?? '',
                'student_id_number' => $log->student?->student_id_number,
                'program' => $program?->code ?? $program?->name ?? '',
                'week_start' => $log->week_start->toDateString(),
                'week_end' => $log->week_end->toDateString(),
                'status' => $log->status,
                'submitted_at' => $log->submitted_at?->toIso8601String(),
            ];
        });

        return response()->json([
            'programs' => $programs,
            'logs' => $logs,
        ]);
    }

    /**
     * One weekly log (in-scope only), shaped like SupervisorJournalController::show
     * so the shared WeeklyJournalPaperView consumes one payload shape —
     * minus `reviewable`, which is supervisor verdict chrome.
     */
    public function show(Request $request, WeeklyLog $weeklyLog): JsonResponse
    {
        $this->authorizeLog($request, $weeklyLog);

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
            'daily_entries' => $dailyEntries,
        ]);
    }

    /**
     * Same document family as the student's own and the supervisor's
     * weekly-log PDFs — reuses pdf.weekly-log so the coordinator's
     * downloaded copy is the identical typed document.
     */
    public function pdf(Request $request, WeeklyLog $weeklyLog): Response
    {
        $this->authorizeLog($request, $weeklyLog);

        $weeklyLog->load('student.program');

        $enrollment = BatchStudent::where('batch_id', $weeklyLog->batch_id)
            ->where('student_id', $weeklyLog->student_id)
            ->with(['company:id,name', 'supervisor:id,name'])
            ->first();

        // Same "Week N" numbering as the student's and supervisor's PDFs:
        // 1-based position among that student's WeeklyLogs by week_start.
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
                'supervisor_name' => $enrollment?->supervisor?->name,
            ],
        ]);

        return $pdf->download("weekly-log-{$weeklyLog->id}.pdf");
    }

    private function authorizeLog(Request $request, WeeklyLog $weeklyLog): void
    {
        $weeklyLog->loadMissing('batch:id,program_id');

        abort_unless(
            $weeklyLog->batch && $request->user()->coordinatorProgramIds()->contains($weeklyLog->batch->program_id),
            403,
            'You do not have access to this weekly journal.'
        );
    }
}
