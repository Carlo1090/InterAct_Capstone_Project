<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Student\Concerns\ResolvesStudentEnrollment;
use App\Http\Requests\Student\StoreWeeklyLogRequest;
use App\Models\BatchStudent;
use App\Models\JournalEntry;
use App\Models\SystemLog;
use App\Models\User;
use App\Models\WeeklyLog;
use App\Services\WeeklyBundlingService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

class WeeklyLogController extends Controller
{
    use ResolvesStudentEnrollment;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $enrollment = $this->currentEnrollment($user->id);

        if (! $enrollment) {
            return response()->json(['message' => 'You are not currently enrolled in an active OJT batch.'], 422);
        }

        $range = $this->ojtRange($enrollment);

        $existingLogs = WeeklyLog::where('student_id', $user->id)
            ->where('batch_id', $enrollment->batch_id)
            ->get()
            ->keyBy(fn (WeeklyLog $log) => $log->week_start->toDateString());

        // The list starts at the Monday of the student's earliest actual
        // work in this batch — first daily entry or earliest weekly log,
        // whichever came first — not at the batch start, so a late starter
        // doesn't see a run of empty weeks. No work at all => no cards yet.
        $firstEntry = JournalEntry::where('student_id', $user->id)
            ->where('batch_id', $enrollment->batch_id)
            ->orderBy('entry_date')
            ->first(['entry_date']);

        $earliestStart = collect([
            $firstEntry?->entry_date->toDateString(),
            $existingLogs->keys()->sort()->first(),
        ])->filter()->min();

        if ($earliestStart === null) {
            return response()->json(['weeks' => []]);
        }

        $listStart = Carbon::parse($earliestStart)->startOfWeek(Carbon::MONDAY);
        $listEnd = $range['end']->copy()->endOfWeek(Carbon::SUNDAY);

        $entryCounts = JournalEntry::where('student_id', $user->id)
            ->whereDate('entry_date', '>=', $listStart->toDateString())
            ->whereDate('entry_date', '<=', $listEnd->toDateString())
            ->get()
            ->groupBy(fn (JournalEntry $entry) => $entry->entry_date->copy()->startOfWeek(Carbon::MONDAY)->toDateString());

        $weeks = [];
        $cursor = $listStart->copy();

        while ($cursor->lessThanOrEqualTo($listEnd)) {
            $weekStartKey = $cursor->toDateString();
            $log = $existingLogs->get($weekStartKey);

            $weeks[] = [
                'week_start' => $weekStartKey,
                'week_end' => $cursor->copy()->addDays(6)->toDateString(),
                'status' => $log?->status,
                'supervisor_comment' => $log?->supervisor_comment,
                'submitted_at' => $log?->submitted_at?->toIso8601String(),
                'entries_count' => $entryCounts->get($weekStartKey)?->count() ?? 0,
            ];

            $cursor = $cursor->addWeek();
        }

        return response()->json(['weeks' => array_reverse($weeks)]);
    }

    public function show(Request $request, string $weekStart): JsonResponse
    {
        $user = $request->user();
        $enrollment = $this->currentEnrollment($user->id);

        if (! $enrollment) {
            return response()->json(['message' => 'You are not currently enrolled in an active OJT batch.'], 422);
        }

        $start = Carbon::parse($weekStart)->startOfWeek(Carbon::MONDAY);
        $end = $start->copy()->addDays(6);

        $log = WeeklyLog::where('student_id', $user->id)
            ->where('batch_id', $enrollment->batch_id)
            ->whereDate('week_start', $start->toDateString())
            ->first();

        // whereDate on both bounds, never whereBetween: entry_date is a
        // date-cast column and SQLite stores it WITH a time component
        // ("2026-08-30 00:00:00"), which sorts after a bare upper bound of
        // "2026-08-30" — silently dropping Sunday, the last day of the week.
        $dailyEntries = JournalEntry::where('student_id', $user->id)
            ->whereDate('entry_date', '>=', $start->toDateString())
            ->whereDate('entry_date', '<=', $end->toDateString())
            ->orderBy('entry_date')
            ->get(['entry_date', 'status', 'content']);

        return response()->json([
            'week_start' => $start->toDateString(),
            'week_end' => $end->toDateString(),
            'status' => $log?->status,
            'supervisor_comment' => $log?->supervisor_comment,
            'submitted_at' => $log?->submitted_at?->toIso8601String(),
            'narrative' => $log?->narrative ?? '',
            // How many of this week's daily entries are actually submitted —
            // the only ones compilation draws from. Lets the page say "Compile
            // from 4 entries" rather than offering a button that 422s.
            'submitted_entries_count' => $dailyEntries->where('status', 'submitted')->count(),
            'sipp_notes' => $this->sippNotesByDay($dailyEntries, $enrollment->batch->journalTemplate?->sections ?? []),
            'daily_entries' => $dailyEntries,
        ]);
    }

    public function pdf(Request $request, string $weekStart): Response
    {
        $user = $request->user();
        $enrollment = $this->currentEnrollment($user->id);

        if (! $enrollment) {
            return response()->json(['message' => 'You are not currently enrolled in an active OJT batch.'], 422);
        }

        $start = Carbon::parse($weekStart)->startOfWeek(Carbon::MONDAY);

        $log = WeeklyLog::where('student_id', $user->id)
            ->where('batch_id', $enrollment->batch_id)
            ->whereDate('week_start', $start->toDateString())
            ->first();

        $pdf = Pdf::loadView('pdf.weekly-log', [
            'narrative' => $log?->narrative ?? '',
            'weekNumber' => $this->weekNumber($user->id, $start),
            'header' => $this->buildHeader($user, $enrollment),
        ]);

        return $pdf->download("weekly-log-{$start->toDateString()}.pdf");
    }

    /**
     * 1-based position of this week among the student's WeeklyLogs ordered
     * by week_start ascending — the N in the document's "My OJT Journal
     * Week N (Company)" title. For a week with no WeeklyLog row yet, this
     * is the position it would take once created.
     */
    private function weekNumber(int $studentId, Carbon $weekStart): int
    {
        return WeeklyLog::where('student_id', $studentId)
            ->whereDate('week_start', '<', $weekStart->toDateString())
            ->count() + 1;
    }

    private function buildHeader(User $user, BatchStudent $enrollment): array
    {
        return [
            'student_name' => $user->name,
            'program' => $user->program?->name,
            'company_name' => $enrollment->company?->name,
            'supervisor_name' => $enrollment->supervisor?->name,
        ];
    }

    /**
     * @param  Collection<int, JournalEntry>  $dailyEntries
     * @param  array<int, array<string, mixed>>  $sections
     * @return array<int, array{entry_date: string, fields: array<int, array{key: string, label: string, text: string}>}>
     */
    private function sippNotesByDay($dailyEntries, array $sections): array
    {
        $sippSections = collect($sections)->filter(fn ($section) => ! empty($section['sipp']))->values();

        return $dailyEntries
            ->map(function (JournalEntry $entry) use ($sippSections) {
                $fields = $sippSections
                    ->filter(fn ($section) => trim((string) ($entry->content[$section['key']] ?? '')) !== '')
                    ->map(fn ($section) => [
                        'key' => $section['key'],
                        'label' => $section['label'],
                        'text' => $entry->content[$section['key']],
                    ])
                    ->values();

                return $fields->isEmpty() ? null : [
                    'entry_date' => $entry->entry_date->toDateString(),
                    'fields' => $fields,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    public function store(StoreWeeklyLogRequest $request): JsonResponse
    {
        $user = $request->user();
        $enrollment = $this->activeEnrollment($user->id);

        if (! $enrollment) {
            return response()->json(['message' => 'You are not currently enrolled in an active OJT batch.'], 422);
        }

        $validated = $request->validated();
        $start = Carbon::parse($validated['week_start'])->startOfWeek(Carbon::MONDAY);
        $end = $start->copy()->addDays(6);

        // Not WeeklyLog::updateOrCreate() with a plain week_start equality
        // match: under SQLite a date-cast column still stores a time
        // component (MySQL truncates it), so a second save for the same
        // week would miss the existing row and insert a duplicate.
        $log = WeeklyLog::where('student_id', $user->id)
            ->where('batch_id', $enrollment->batch_id)
            ->whereDate('week_start', $start->toDateString())
            ->first();

        if ($lockMessage = $this->submittedLockMessage($log)) {
            return response()->json(['message' => $lockMessage], 422);
        }

        if ($log) {
            $log->update(['week_end' => $end->toDateString(), 'narrative' => $validated['narrative'] ?? null]);
        } else {
            $log = WeeklyLog::create([
                'student_id' => $user->id,
                'batch_id' => $enrollment->batch_id,
                'week_start' => $start->toDateString(),
                'week_end' => $end->toDateString(),
                'narrative' => $validated['narrative'] ?? null,
            ]);
        }

        return response()->json($log);
    }

    /**
     * Compile this week's narrative from the student's own submitted daily
     * entries, on demand.
     *
     * Bundling used to be something that only happened TO a student, once a
     * week, overnight — so an intern who submitted Friday's entry on Saturday
     * had missed the bus, and an intern catching up on a fortnight of entries
     * had no way to fold them in at all. This is the same compiler
     * (WeeklyBundlingService, one writer for both paths), triggered by the
     * person whose work it is.
     *
     * It overwrites whatever is in the narrative box, which is exactly what it
     * is for — so the button confirms first on the client. A log already with
     * the supervisor is refused; a returned one recompiles, since that is the
     * revision path.
     */
    public function bundle(Request $request, string $weekStart, WeeklyBundlingService $bundler): JsonResponse
    {
        $user = $request->user();
        $enrollment = $this->activeEnrollment($user->id);

        if (! $enrollment) {
            return response()->json(['message' => 'You are not currently enrolled in an active OJT batch.'], 422);
        }

        $start = Carbon::parse($weekStart)->startOfWeek(Carbon::MONDAY);
        $end = $start->copy()->addDays(6);

        // The current week is allowed on purpose (compile what you have so
        // far); a week that has not begun has nothing to compile.
        if ($start->isAfter(today()->startOfWeek(Carbon::MONDAY))) {
            return response()->json(['message' => 'That week has not started yet.'], 422);
        }

        if ($end->lessThan($this->ojtRange($enrollment)['start'])) {
            return response()->json(['message' => 'That week is before your OJT started.'], 422);
        }

        $submittedCount = JournalEntry::where('student_id', $user->id)
            ->where('status', 'submitted')
            ->whereDate('entry_date', '>=', $start->toDateString())
            ->whereDate('entry_date', '<=', $end->toDateString())
            ->count();

        if ($submittedCount === 0) {
            return response()->json([
                'message' => 'There are no submitted daily entries in this week yet. Submit your daily journals first, then compile.',
            ], 422);
        }

        $log = $bundler->bundleForStudent($user->id, $enrollment->batch_id, $start);

        if (! $log) {
            return response()->json([
                'message' => 'This weekly log is already with your supervisor and can no longer be recompiled.',
            ], 422);
        }

        return response()->json([
            'message' => "Compiled from {$submittedCount} submitted daily ".($submittedCount === 1 ? 'entry' : 'entries').'.',
            'narrative' => $log->narrative ?? '',
            'log' => $log->fresh(),
        ]);
    }

    /**
     * Submit the already-saved draft narrative for review: sets submitted_at
     * + status='pending', making it reachable by SupervisorJournalController
     * (which only lists whereNotNull('submitted_at')). Allowed again after a
     * supervisor returns it (status='returned'), which is what makes the
     * return-with-comment flow meaningful.
     */
    public function submit(Request $request, string $weekStart): JsonResponse
    {
        $user = $request->user();
        $enrollment = $this->activeEnrollment($user->id);

        if (! $enrollment) {
            return response()->json(['message' => 'You are not currently enrolled in an active OJT batch.'], 422);
        }

        $start = Carbon::parse($weekStart)->startOfWeek(Carbon::MONDAY);

        // Same SQLite-safe lookup as store()/show(): a date-cast column can
        // still carry a time component under SQLite, so plain equality can
        // miss the row that was just saved.
        $log = WeeklyLog::where('student_id', $user->id)
            ->where('batch_id', $enrollment->batch_id)
            ->whereDate('week_start', $start->toDateString())
            ->first();

        if (! $log || trim((string) $log->narrative) === '') {
            return response()->json(['message' => 'Save a draft for this week before submitting.'], 422);
        }

        if ($lockMessage = $this->submittedLockMessage($log)) {
            return response()->json(['message' => $lockMessage], 422);
        }

        $log->update([
            'submitted_at' => now(),
            'status' => 'pending',
        ]);

        SystemLog::record('Weekly Journal Submitted', "{$user->name} submitted their journal for week of {$start->toDateString()}");

        return response()->json($log->fresh());
    }

    /**
     * Non-null once submitted_at is set AND the log is still pending/approved
     * (blocks further edits and double-submits); a 'returned' log is always
     * editable/resubmittable again regardless of its old submitted_at.
     */
    private function submittedLockMessage(?WeeklyLog $log): ?string
    {
        if (! $log || $log->submitted_at === null || ! in_array($log->status, ['pending', 'approved'], true)) {
            return null;
        }

        return $log->status === 'approved'
            ? 'This weekly log has already been approved and can no longer be edited.'
            : 'This weekly log has already been submitted and is awaiting review.';
    }
}
