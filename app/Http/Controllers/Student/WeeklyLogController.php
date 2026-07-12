<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Student\Concerns\ResolvesStudentEnrollment;
use App\Http\Requests\Student\StoreWeeklyLogRequest;
use App\Models\BatchStudent;
use App\Models\JournalEntry;
use App\Models\User;
use App\Models\WeeklyLog;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class WeeklyLogController extends Controller
{
    use ResolvesStudentEnrollment;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $enrollment = $this->activeEnrollment($user->id);

        if (! $enrollment) {
            return response()->json(['message' => 'You are not currently enrolled in an active OJT batch.'], 422);
        }

        $range = $this->ojtRange($enrollment);

        $existingLogs = WeeklyLog::where('student_id', $user->id)
            ->where('batch_id', $enrollment->batch_id)
            ->get()
            ->keyBy(fn (WeeklyLog $log) => $log->week_start->toDateString());

        $entryCounts = JournalEntry::where('student_id', $user->id)
            ->whereBetween('entry_date', [$range['start']->toDateString(), $range['end']->toDateString()])
            ->get()
            ->groupBy(fn (JournalEntry $entry) => $entry->entry_date->copy()->startOfWeek(Carbon::MONDAY)->toDateString());

        $weeks = [];
        $cursor = $range['start']->copy()->startOfWeek(Carbon::MONDAY);
        $end = $range['end']->copy()->endOfWeek(Carbon::SUNDAY);

        while ($cursor->lessThanOrEqualTo($end)) {
            $weekStartKey = $cursor->toDateString();
            $log = $existingLogs->get($weekStartKey);

            $weeks[] = [
                'week_start' => $weekStartKey,
                'week_end' => $cursor->copy()->addDays(6)->toDateString(),
                'status' => $log->status ?? null,
                'supervisor_comment' => $log->supervisor_comment ?? null,
                'entries_count' => $entryCounts->get($weekStartKey)?->count() ?? 0,
            ];

            $cursor = $cursor->addWeek();
        }

        return response()->json(['weeks' => array_reverse($weeks)]);
    }

    public function show(Request $request, string $weekStart): JsonResponse
    {
        $user = $request->user();
        $enrollment = $this->activeEnrollment($user->id);

        if (! $enrollment) {
            return response()->json(['message' => 'You are not currently enrolled in an active OJT batch.'], 422);
        }

        $start = Carbon::parse($weekStart)->startOfWeek(Carbon::MONDAY);
        $end = $start->copy()->addDays(6);

        $log = WeeklyLog::where('student_id', $user->id)
            ->where('batch_id', $enrollment->batch_id)
            ->whereDate('week_start', $start->toDateString())
            ->first();

        $dailyEntries = JournalEntry::where('student_id', $user->id)
            ->whereBetween('entry_date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('entry_date')
            ->get(['entry_date', 'status', 'content']);

        return response()->json([
            'week_start' => $start->toDateString(),
            'week_end' => $end->toDateString(),
            'status' => $log->status ?? null,
            'supervisor_comment' => $log->supervisor_comment ?? null,
            'narrative' => $log->narrative ?? '',
            'sipp_notes' => $this->sippNotesByDay($dailyEntries, $enrollment->batch->journalTemplate?->sections ?? []),
            'daily_entries' => $dailyEntries,
        ]);
    }

    public function pdf(Request $request, string $weekStart): Response
    {
        $user = $request->user();
        $enrollment = $this->activeEnrollment($user->id);

        if (! $enrollment) {
            return response()->json(['message' => 'You are not currently enrolled in an active OJT batch.'], 422);
        }

        $start = Carbon::parse($weekStart)->startOfWeek(Carbon::MONDAY);
        $end = $start->copy()->addDays(6);

        $log = WeeklyLog::where('student_id', $user->id)
            ->where('batch_id', $enrollment->batch_id)
            ->whereDate('week_start', $start->toDateString())
            ->first();

        $pdf = Pdf::loadView('pdf.weekly-log', [
            'weekStart' => $start->toDateString(),
            'weekEnd' => $end->toDateString(),
            'status' => $log->status ?? 'pending',
            'narrative' => $log->narrative ?? '',
            'supervisorComment' => $log->supervisor_comment ?? null,
            'submittedAt' => $log?->submitted_at,
            'header' => $this->buildHeader($user, $enrollment),
        ]);

        return $pdf->download("weekly-log-{$start->toDateString()}.pdf");
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
     * @param  \Illuminate\Support\Collection<int, JournalEntry>  $dailyEntries
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
