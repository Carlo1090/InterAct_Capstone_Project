<?php

namespace App\Http\Controllers\Coordinator;

use App\Http\Controllers\Controller;
use App\Models\BatchStudent;
use App\Models\JournalEntry;
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
     * submitted today). Accepts `from`/`to` (Y-m-d) for a date range and an
     * optional `company_id` filter. Over a multi-day range each row carries
     * that student's submitted/missing tally.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'company_id' => ['nullable', 'integer'],
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

        $programIds = $request->user()->coordinatorProgramIds();

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
            ->sortBy('student_name')
            ->values();

        return response()->json([
            'from' => $from,
            'to' => $to,
            'is_single_day' => $isSingleDay,
            'companies' => $companies,
            'rows' => $rows,
        ]);
    }
}
