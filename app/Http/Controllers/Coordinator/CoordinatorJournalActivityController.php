<?php

namespace App\Http\Controllers\Coordinator;

use App\Http\Controllers\Controller;
use App\Models\BatchStudent;
use App\Models\JournalEntry;
use App\Models\Program;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CoordinatorJournalActivityController extends Controller
{
    /**
     * Statuses that represent a daily journal the student failed to submit.
     */
    private const MISSING_STATUSES = ['missing', 'overdue'];

    /**
     * Daily journal monitoring for enrolled students in scope.
     *
     * Default view = today (each in-scope active student + whether they
     * submitted today). Accepts `from`/`to` (Y-m-d) for a date range and
     * optional `company_id`, `program_id`, and `status` (submitted|missing)
     * filters. Over a multi-day range each row carries that student's
     * submitted/missing tally.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'company_id' => ['nullable', 'integer'],
            'program_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:submitted,missing'],
        ]);

        $today = now()->toDateString();
        $from = $validated['from'] ?? $validated['to'] ?? $today;
        $to = $validated['to'] ?? $validated['from'] ?? $today;

        // Tolerate a reversed range rather than erroring.
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $isSingleDay = $from === $to;
        $companyId = $validated['company_id'] ?? null;
        $statusFilter = $validated['status'] ?? null;

        $scopedProgramIds = $request->user()->coordinatorProgramIds();
        $programIds = $scopedProgramIds;

        if (! empty($validated['program_id'])) {
            $requestedProgramId = (int) $validated['program_id'];
            abort_unless($scopedProgramIds->contains($requestedProgramId), 403, 'That program is outside your assigned department(s).');
            $programIds = collect([$requestedProgramId]);
        }

        // The coordinator's full program scope — powers the Program filter
        // dropdown regardless of which program is currently selected.
        $programs = Program::whereIn('id', $scopedProgramIds)
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        $baseScope = fn () => BatchStudent::where('status', 'active')
            ->whereHas('batch', fn ($query) => $query->whereIn('program_id', $programIds));

        // Companies used by in-scope active interns — powers the filter dropdown.
        $companies = (clone $baseScope())
            ->with('company:id,name')
            ->get()
            ->pluck('company')
            ->filter()
            ->unique('id')
            ->sortBy('name')
            ->values()
            ->map(fn ($company) => ['id' => $company->id, 'name' => $company->name]);

        $enrollments = $baseScope()
            ->when($companyId, fn ($query) => $query->where('company_id', $companyId))
            ->with(['student:id,name', 'company:id,name', 'batch.program:id,code,name'])
            ->get();

        $studentIds = $enrollments->pluck('student_id');

        // whereDate (not whereBetween on the raw string) so same-day matches work
        // regardless of how the driver stores the date column (SQLite keeps a time).
        $entries = JournalEntry::whereIn('student_id', $studentIds)
            ->whereDate('entry_date', '>=', $from)
            ->whereDate('entry_date', '<=', $to)
            ->get(['student_id', 'entry_date', 'status', 'submitted_at'])
            ->groupBy('student_id');

        $rows = $enrollments->map(function (BatchStudent $enrollment) use ($entries, $isSingleDay) {
            $studentEntries = $entries->get($enrollment->student_id, collect());

            $submittedCount = $studentEntries->where('status', 'submitted')->count();
            $missingCount = $studentEntries->whereIn('status', self::MISSING_STATUSES)->count();

            $todayEntry = $studentEntries->firstWhere('status', 'submitted');
            $program = $enrollment->batch?->program;

            return [
                'student_id' => $enrollment->student_id,
                'student_name' => $enrollment->student?->name ?? '',
                'company_id' => $enrollment->company_id,
                'company' => $enrollment->company?->name ?? '',
                'program' => $program?->code ?? $program?->name ?? '',
                'submitted_count' => $submittedCount,
                'missing_count' => $missingCount,
                // Single-day convenience fields.
                'day_status' => $isSingleDay ? ($submittedCount > 0 ? 'submitted' : 'missing') : null,
                'submitted_at' => $isSingleDay ? $todayEntry?->submitted_at?->toIso8601String() : null,
            ];
        })
            ->when($statusFilter, fn ($rows) => $rows->filter(function (array $row) use ($statusFilter, $isSingleDay) {
                if ($isSingleDay) {
                    return $row['day_status'] === $statusFilter;
                }

                return $statusFilter === 'submitted' ? $row['submitted_count'] > 0 : $row['missing_count'] > 0;
            }))
            ->sortBy('student_name')
            ->values();

        return response()->json([
            'from' => $from,
            'to' => $to,
            'is_single_day' => $isSingleDay,
            'companies' => $companies,
            'programs' => $programs,
            'rows' => $rows,
        ]);
    }

    /**
     * One student's full journal entry for a given day — every section label +
     * the text they wrote, plus date/status/submitted_at. Read-only, scoped to
     * the coordinator's active in-scope students (403 out of scope, 404 if the
     * student has no active enrollment at all).
     */
    public function show(Request $request, User $student, string $date): JsonResponse
    {
        abort_unless($student->role === 'student', 404);

        $enrollment = BatchStudent::where('student_id', $student->id)
            ->where('status', 'active')
            ->with('batch.journalTemplate')
            ->latest('enrolled_at')
            ->first();

        abort_unless($enrollment, 404, 'This student has no active enrollment.');
        abort_unless(
            $request->user()->coordinatorProgramIds()->contains($enrollment->batch->program_id),
            403,
            'You do not have access to this student.'
        );

        $entryDate = Carbon::parse($date)->startOfDay();

        $entry = JournalEntry::where('student_id', $student->id)
            ->whereDate('entry_date', $entryDate)
            ->first();

        $sections = collect($enrollment->batch->journalTemplate?->sections ?? [])
            ->map(fn (array $section) => [
                'key' => $section['key'],
                'label' => $section['label'],
                'text' => $entry?->content[$section['key']] ?? null,
            ])
            ->values();

        return response()->json([
            'student_id' => $student->id,
            'student_name' => $student->name,
            'entry_date' => $entryDate->toDateString(),
            'status' => $entry->status ?? 'missing',
            'submitted_at' => $entry?->submitted_at?->toIso8601String(),
            'sections' => $sections,
        ]);
    }
}
