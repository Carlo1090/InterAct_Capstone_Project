<?php

namespace App\Http\Controllers\Coordinator;

use App\Http\Controllers\Concerns\BuildsWeeklyActivityLogPdf;
use App\Http\Controllers\Controller;
use App\Models\Program;
use App\Models\WeeklyActivityEntry;
use App\Models\WeeklyActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The coordinator's read-only window onto the Weekly Activity Log and Time Log
 * Summary — the same posture as CoordinatorWeeklyJournalController: observe and
 * download, never edit. The sheet is the student's own work and a supervisor
 * signs the paper copy; a coordinator collecting them for the SIPP file needs
 * to read and print them, nothing more.
 *
 * Deliberately shaped like the Student Info Sheets queue (scope by the batch's
 * program, filter, open one, download the official PDF) because that is the
 * flow the coordinator already knows — minus Accept/Reject, which has no
 * meaning here: there is no approval step on this form.
 */
class CoordinatorWeeklyActivityLogController extends Controller
{
    use BuildsWeeklyActivityLogPdf;

    /**
     * Every in-scope student's log sheets, newest period first.
     *
     * Unlike the weekly-journal queue this does NOT filter on a submitted
     * state: the form has no submit step, so excluding anything unsubmitted
     * would hide every sheet in the system.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'program_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $scopedProgramIds = $request->user()->coordinatorProgramIds();
        $programIds = $scopedProgramIds;

        if (! empty($validated['program_id'])) {
            $requested = (int) $validated['program_id'];
            abort_unless($scopedProgramIds->contains($requested), 403, 'That program is outside your assigned department(s).');
            $programIds = collect([$requested]);
        }

        $from = $validated['from'] ?? null;
        $to = $validated['to'] ?? null;

        // Tolerate a reversed range rather than erroring — same as the
        // weekly-journal queue.
        if ($from && $to && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        $logs = WeeklyActivityLog::whereHas('batch', fn ($query) => $query->whereIn('program_id', $programIds))
            ->when(
                $validated['search'] ?? null,
                fn ($query, $search) => $query->whereHas('student', fn ($q) => $q->where('name', 'like', '%'.$search.'%'))
            )
            ->when($from, fn ($query) => $query->whereDate('week_end', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('week_start', '<=', $to))
            ->withCount('entries')
            ->with(['student:id,name,student_id_number', 'batch.program:id,code,name'])
            ->orderByDesc('week_start')
            ->orderBy('student_id')
            ->paginate(20)
            ->withQueryString();

        $logs->through(function (WeeklyActivityLog $log) {
            $program = $log->batch?->program;

            return [
                'id' => $log->id,
                'student_id' => $log->student_id,
                'student_name' => $log->student?->name ?? '',
                'student_id_number' => $log->student?->student_id_number,
                'program' => $program?->code ?? $program?->name ?? '',
                'week_start' => $log->week_start?->toDateString(),
                'week_end' => $log->week_end?->toDateString(),
                'area_assigned' => $log->area_assigned,
                'no_of_hours' => $log->no_of_hours,
                'entries_count' => $log->entries_count,
            ];
        });

        return response()->json([
            'programs' => Program::whereIn('id', $scopedProgramIds)->orderBy('name')->get(['id', 'name', 'code']),
            'logs' => $logs,
        ]);
    }

    /**
     * One in-scope sheet, shaped so the frontend can render the same read-only
     * paper table the student types into.
     */
    public function show(Request $request, WeeklyActivityLog $weeklyActivityLog): JsonResponse
    {
        $this->assertInScope($request, $weeklyActivityLog);

        $weeklyActivityLog->load(['entries' => fn ($query) => $query->orderBy('sort_order')]);

        return response()->json([
            'id' => $weeklyActivityLog->id,
            'student_id' => $weeklyActivityLog->student_id,
            'week_start' => $weeklyActivityLog->week_start?->toDateString(),
            'week_end' => $weeklyActivityLog->week_end?->toDateString(),
            'area_assigned' => $weeklyActivityLog->area_assigned,
            'no_of_hours' => $weeklyActivityLog->no_of_hours,
            'header' => $this->weeklyActivityLogHeader($weeklyActivityLog),
            'entries' => $weeklyActivityLog->entries->map(fn (WeeklyActivityEntry $entry) => [
                'id' => $entry->id,
                'inclusive_date_start' => $entry->inclusive_date_start?->toDateString(),
                'inclusive_date_end' => $entry->inclusive_date_end?->toDateString(),
                'activities' => $entry->activities,
                'documents_records' => $entry->documents_records,
                'objectives' => $entry->objectives,
                'supervisor_name' => $entry->supervisor_name,
                'supervisor_position' => $entry->supervisor_position,
            ])->values(),
        ]);
    }

    /**
     * The same measured facsimile the student downloads — one renderer, so the
     * coordinator's filed copy and the student's printed copy cannot differ.
     */
    public function pdf(Request $request, WeeklyActivityLog $weeklyActivityLog): Response
    {
        $this->assertInScope($request, $weeklyActivityLog);

        $weeklyActivityLog->loadMissing('student:id,name');

        $studentSlug = str($weeklyActivityLog->student?->name ?? (string) $weeklyActivityLog->student_id)->slug();
        $weekSlug = str($weeklyActivityLog->week_start?->toDateString() ?? (string) $weeklyActivityLog->id)->slug();

        return $this->renderWeeklyActivityLogPdf(
            $weeklyActivityLog,
            "weekly-activity-log-{$studentSlug}-{$weekSlug}.pdf",
        );
    }

    private function assertInScope(Request $request, WeeklyActivityLog $log): void
    {
        $log->loadMissing('batch:id,program_id');

        abort_unless(
            $log->batch && $request->user()->coordinatorProgramIds()->contains($log->batch->program_id),
            403,
            'This log sheet is not in your scope.'
        );
    }
}
