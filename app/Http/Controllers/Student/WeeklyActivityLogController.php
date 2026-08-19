<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Student\Concerns\ResolvesStudentEnrollment;
use App\Http\Requests\Student\ReorderWeeklyActivityEntriesRequest;
use App\Http\Requests\Student\StoreWeeklyActivityEntryRequest;
use App\Http\Requests\Student\StoreWeeklyActivityLogRequest;
use App\Http\Requests\Student\UpdateWeeklyActivityEntryRequest;
use App\Http\Requests\Student\UpdateWeeklyActivityLogRequest;
use App\Models\User;
use App\Models\WeeklyActivityEntry;
use App\Models\WeeklyActivityLog;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class WeeklyActivityLogController extends Controller
{
    use ResolvesStudentEnrollment;

    /**
     * The paper form is the CABM/Business Department edition, and these two
     * lines are printed on it verbatim. Deliberately literal rather than
     * derived from `departments.name`, which is seeded to the short code
     * ("CABM-B") and would print wrongly — the same call already made for the
     * GROUP Student Information Sheet.
     */
    private const DEFAULT_DEPARTMENT_LINE = 'College of Accountancy, Business and Management';

    private const DEFAULT_UNIT_LINE = 'Business Department';

    /**
     * The blank form carries pre-printed rows. Pad to this many so a sparse log
     * still prints like the paper form; the table itself GROWS past it.
     */
    private const MIN_FORM_ROWS = 5;

    public function index(Request $request): JsonResponse
    {
        $logs = WeeklyActivityLog::where('student_id', $request->user()->id)
            ->orderByDesc('week_start')
            ->get();

        return response()->json($logs);
    }

    public function store(StoreWeeklyActivityLogRequest $request): JsonResponse
    {
        $user = $request->user();
        $enrollment = $this->activeEnrollment($user->id);

        if (! $enrollment) {
            return response()->json(['message' => 'You are not currently enrolled in an active OJT batch.'], 422);
        }

        $log = WeeklyActivityLog::create([
            ...$request->validated(),
            'student_id' => $user->id,
            'batch_id' => $enrollment->batch_id,
        ]);

        return response()->json($log, 201);
    }

    public function show(Request $request, WeeklyActivityLog $weeklyActivityLog): JsonResponse
    {
        $this->authorizeOwnership($weeklyActivityLog, $request->user()->id);

        return response()->json([
            ...$weeklyActivityLog->load(['entries' => fn ($query) => $query->orderBy('sort_order')])->toArray(),
            'header' => $this->displayHeader($request->user()),
        ]);
    }

    public function update(UpdateWeeklyActivityLogRequest $request, WeeklyActivityLog $weeklyActivityLog): JsonResponse
    {
        $this->authorizeOwnership($weeklyActivityLog, $request->user()->id);

        $weeklyActivityLog->update($request->validated());

        return response()->json($weeklyActivityLog);
    }

    public function storeEntry(StoreWeeklyActivityEntryRequest $request, WeeklyActivityLog $weeklyActivityLog): JsonResponse
    {
        $this->authorizeOwnership($weeklyActivityLog, $request->user()->id);

        $nextSortOrder = ((int) $weeklyActivityLog->entries()->max('sort_order')) + 1;

        $entry = $weeklyActivityLog->entries()->create([
            ...$request->validated(),
            'sort_order' => $nextSortOrder,
        ]);

        return response()->json($entry, 201);
    }

    public function updateEntry(UpdateWeeklyActivityEntryRequest $request, WeeklyActivityLog $weeklyActivityLog, WeeklyActivityEntry $entry): JsonResponse
    {
        $this->authorizeOwnership($weeklyActivityLog, $request->user()->id);
        $this->authorizeEntry($entry, $weeklyActivityLog);

        $entry->update($request->validated());

        return response()->json($entry);
    }

    public function destroyEntry(Request $request, WeeklyActivityLog $weeklyActivityLog, WeeklyActivityEntry $entry): JsonResponse
    {
        $this->authorizeOwnership($weeklyActivityLog, $request->user()->id);
        $this->authorizeEntry($entry, $weeklyActivityLog);

        $entry->delete();

        return response()->json(['message' => 'Entry removed.']);
    }

    public function reorderEntries(ReorderWeeklyActivityEntriesRequest $request, WeeklyActivityLog $weeklyActivityLog): JsonResponse
    {
        $this->authorizeOwnership($weeklyActivityLog, $request->user()->id);

        $validIds = $weeklyActivityLog->entries()->pluck('id')->all();

        foreach ($request->validated()['entry_ids'] as $index => $entryId) {
            if (in_array($entryId, $validIds, true)) {
                WeeklyActivityEntry::where('id', $entryId)->update(['sort_order' => $index]);
            }
        }

        return response()->json(
            $weeklyActivityLog->entries()->orderBy('sort_order')->get()
        );
    }

    public function pdf(Request $request, WeeklyActivityLog $weeklyActivityLog): Response
    {
        $this->authorizeOwnership($weeklyActivityLog, $request->user()->id);

        $weeklyActivityLog->load(['entries' => fn ($query) => $query->orderBy('sort_order')]);

        $pdf = Pdf::loadView('pdf.weekly-activity-log', [
            'log' => $weeklyActivityLog,
            'header' => $this->displayHeader($request->user()),
            'periodCovered' => $this->formatRange($weeklyActivityLog->week_start, $weeklyActivityLog->week_end),
            'hours' => $this->formatHours($weeklyActivityLog->no_of_hours),
            'rows' => $this->formRows($weeklyActivityLog),
            // dompdf defaults to A4, which silently narrows every measured column.
        ])->setPaper('letter', 'portrait');

        $slug = str($weeklyActivityLog->week_start?->toDateString() ?? (string) $weeklyActivityLog->id)->slug();

        return $pdf->download("weekly-activity-log-{$slug}.pdf");
    }

    /**
     * One row per entry, padded out to the blank form's pre-printed row count.
     */
    private function formRows(WeeklyActivityLog $log): array
    {
        $rows = $log->entries->map(fn (WeeklyActivityEntry $entry) => [
            'dates' => $this->formatRange($entry->inclusive_date_start, $entry->inclusive_date_end),
            'activities' => $entry->activities,
            'documents_records' => $entry->documents_records,
            'objectives' => $entry->objectives,
            'supervisor_name' => $entry->supervisor_name,
            'supervisor_position' => $entry->supervisor_position,
        ])->all();

        $blank = [
            'dates' => '', 'activities' => '', 'documents_records' => '',
            'objectives' => '', 'supervisor_name' => '', 'supervisor_position' => '',
        ];

        while (count($rows) < self::MIN_FORM_ROWS) {
            $rows[] = $blank;
        }

        return $rows;
    }

    private function formatRange(mixed $start, mixed $end): string
    {
        if (! $start && ! $end) {
            return '';
        }

        if (! $start || ! $end) {
            return ($start ?? $end)->format('M j, Y');
        }

        // Same year and month reads as "Jan 1 - 5, 2026"; otherwise spell both out.
        if ($start->isSameMonth($end)) {
            return $start->format('M j').' - '.$end->format('j, Y');
        }

        return $start->format('M j').' - '.$end->format('M j, Y');
    }

    private function formatHours(mixed $hours): string
    {
        if ($hours === null || $hours === '') {
            return '';
        }

        // decimal(5,1) comes back as "40.0" — drop a pointless trailing zero.
        return rtrim(rtrim(number_format((float) $hours, 1, '.', ''), '0'), '.');
    }

    private function authorizeOwnership(WeeklyActivityLog $log, int $userId): void
    {
        abort_unless((int) $log->student_id === $userId, 403);
    }

    private function authorizeEntry(WeeklyActivityEntry $entry, WeeklyActivityLog $log): void
    {
        abort_unless((int) $entry->weekly_activity_log_id === $log->id, 404);
    }

    private function displayHeader(User $user): array
    {
        $enrollment = $this->activeEnrollment($user->id);
        $profile = $user->studentProfile;

        $program = $user->program?->code ?? $user->program?->name;
        $year = $profile?->year_level;

        return [
            'student_name' => $user->name,
            'program' => $user->program?->name,
            'year_level' => $year,
            'coordinator_name' => $enrollment?->batch?->coordinator?->name,
            'company_name' => $enrollment?->company?->name,
            'supervisor_name' => $enrollment?->supervisor?->name,
            'area_assigned' => $enrollment?->assigned_division,

            // Composed for the paper form's own field labels.
            'department_line' => self::DEFAULT_DEPARTMENT_LINE,
            'unit_line' => self::DEFAULT_UNIT_LINE,
            'program_and_year' => trim(collect([$program, $year])->filter()->implode(' ')),
            // The form says "Faculty Adviser"; this system has no adviser role,
            // so the batch coordinator is the person that field means.
            'faculty_adviser' => $enrollment?->batch?->coordinator?->name,
        ];
    }
}
