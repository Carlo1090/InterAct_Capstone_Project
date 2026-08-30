<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Student\Concerns\ResolvesStudentEnrollment;
use App\Http\Requests\Student\StoreJournalEntryRequest;
use App\Models\BatchStudent;
use App\Models\JournalEntry;
use App\Models\SystemLog;
use App\Models\User;
use App\Models\WeeklyLog;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class JournalEntryController extends Controller
{
    use ResolvesStudentEnrollment;

    /**
     * One message per lockedReason() token, so show()'s banner and store()'s
     * rejection can never explain the same lock two different ways.
     */
    private const LOCK_MESSAGES = [
        'not_active' => 'Your OJT enrollment is no longer active, so journal entries can no longer be edited.',
        'range' => 'This date is outside your OJT range or is a future date.',
        'week_submitted' => 'This week has already been submitted to your supervisor. Ask them to return it if you need to change a daily entry.',
    ];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $entries = JournalEntry::where('student_id', $user->id)
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('from'), fn ($query) => $query->whereDate('entry_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($query) => $query->whereDate('entry_date', '<=', $request->date('to')))
            ->orderByDesc('entry_date')
            ->paginate(20);

        $entries->getCollection()->transform(function (JournalEntry $entry) {
            $entry->setAttribute('word_count', $this->wordCount($entry->content ?? []));

            return $entry;
        });

        return response()->json($entries);
    }

    public function show(Request $request, string $date): JsonResponse
    {
        $user = $request->user();
        $enrollment = $this->currentEnrollment($user->id);

        if (! $enrollment) {
            return response()->json(['message' => 'You are not currently enrolled in an active OJT batch.'], 422);
        }

        $entryDate = Carbon::parse($date)->startOfDay();

        $entry = JournalEntry::where('student_id', $user->id)
            ->whereDate('entry_date', $entryDate)
            ->first();

        return response()->json([
            'entry_date' => $entryDate->toDateString(),
            'sections' => $enrollment->batch->journalTemplate?->sections ?? [],
            'char_limit' => $enrollment->batch->journalTemplate?->char_limit ?? 1500,
            'status' => $entry->status ?? 'draft',
            'content' => $entry->content ?? [],
            'submitted_at' => $entry?->submitted_at,
            'editable' => $this->isEditableDate($entryDate, $enrollment),
            'locked_reason' => $this->lockedReason($entryDate, $enrollment),
            'student_name' => $user->name,
            'program' => $user->program?->name,
            // Weekday name of the entry date (e.g. "Sunday"); the document
            // renders it as "Sunday (MM-DD-YYYY)".
            'day_label' => $entryDate->format('l'),
        ]);
    }

    public function store(StoreJournalEntryRequest $request): JsonResponse
    {
        $user = $request->user();
        $enrollment = $this->activeEnrollment($user->id);

        if (! $enrollment) {
            return response()->json(['message' => 'You are not currently enrolled in an active OJT batch.'], 422);
        }

        $validated = $request->validated();
        $entryDate = Carbon::parse($validated['entry_date'])->startOfDay();

        if ($reason = $this->lockedReason($entryDate, $enrollment)) {
            return response()->json(['message' => self::LOCK_MESSAGES[$reason]], 422);
        }

        $existing = JournalEntry::where('student_id', $user->id)
            ->whereDate('entry_date', $entryDate)
            ->first();

        $attributes = [
            'batch_id' => $enrollment->batch_id,
            'content' => $validated['content'],
            'status' => $validated['status'],
            'submitted_at' => $validated['status'] === 'submitted' ? now() : null,
        ];

        // updateOrCreate()'s match array is a plain equality check, which can
        // miss this row under SQLite where a date-cast column still stores a
        // time component (unlike MySQL, which truncates it) — update the
        // already-fetched row directly instead, mirroring WeeklyBundlingService.
        if ($existing) {
            $existing->update($attributes);
            $entry = $existing;
        } else {
            $entry = JournalEntry::create([
                'student_id' => $user->id,
                'entry_date' => $entryDate->toDateString(),
                ...$attributes,
            ]);
        }

        if ($validated['status'] === 'submitted') {
            SystemLog::record('Daily Journal Submitted', "{$user->name} submitted their journal for {$entryDate->toDateString()}");
        }

        return response()->json($entry);
    }

    public function pdf(Request $request, string $date): Response
    {
        $user = $request->user();
        $enrollment = $this->currentEnrollment($user->id);

        if (! $enrollment) {
            return response()->json(['message' => 'You are not currently enrolled in an active OJT batch.'], 422);
        }

        $entryDate = Carbon::parse($date)->startOfDay();

        $entry = JournalEntry::where('student_id', $user->id)
            ->whereDate('entry_date', $entryDate)
            ->first();

        $pdf = Pdf::loadView('pdf.daily-journal-entry', [
            'entryDate' => $entryDate->toDateString(),
            'sections' => $enrollment->batch->journalTemplate?->sections ?? [],
            'content' => $entry->content ?? [],
            'dayLabel' => $entryDate->format('l'),
            'header' => $this->buildHeader($user, $enrollment),
        ]);

        return $pdf->download("daily-journal-{$entryDate->toDateString()}.pdf");
    }

    private function buildHeader(User $user, BatchStudent $enrollment): array
    {
        return [
            'student_name' => $user->name,
            'program' => $user->program?->name,
            'company_name' => $enrollment->company?->name,
        ];
    }

    /**
     * A date is writable only while the enrollment is still active (a
     * completed/dropped student reads but never writes), only for dates
     * inside the real-time window (batch start .. today), and only until the
     * week it belongs to has actually been SUBMITTED for supervisor review.
     */
    private function isEditableDate(Carbon $date, BatchStudent $enrollment): bool
    {
        return $this->lockedReason($date, $enrollment) === null;
    }

    /**
     * True once the WeeklyLog covering this date has been submitted for review
     * and is still pending or approved — i.e. the supervisor is looking at it,
     * or has already signed it off.
     *
     * The mere EXISTENCE of a WeeklyLog is deliberately NOT a lock any more.
     * WeeklyBundlingService stamps one every Monday for every active student,
     * so the old rule meant a student who fell a single day behind could never
     * write last week's entries again — the exact catching-up the coordinator
     * needs them to be able to do. Compilation is now reversible (a student can
     * recompile the week themselves); a supervisor's review is not, which is
     * why that is where the line sits. A 'returned' log reopens its week, so
     * revision after a return works the same way it does on the narrative.
     */
    private function isWeekUnderReview(int $studentId, int $batchId, Carbon $date): bool
    {
        $monday = $date->copy()->startOfWeek(Carbon::MONDAY);

        return WeeklyLog::where('student_id', $studentId)
            ->where('batch_id', $batchId)
            ->whereDate('week_start', $monday->toDateString())
            ->whereNotNull('submitted_at')
            ->whereIn('status', ['pending', 'approved'])
            ->exists();
    }

    /**
     * Which guard is blocking edits, if any — null means editable. Lets the
     * frontend show the right banner copy instead of one generic message.
     */
    private function lockedReason(Carbon $date, BatchStudent $enrollment): ?string
    {
        if ($enrollment->status !== 'active') {
            return 'not_active';
        }

        if ($date->isAfter(today()) || $date->lessThan($this->ojtRange($enrollment)['start'])) {
            return 'range';
        }

        if ($this->isWeekUnderReview($enrollment->student_id, $enrollment->batch_id, $date)) {
            return 'week_submitted';
        }

        return null;
    }

    private function wordCount(array $content): int
    {
        return collect($content)->sum(fn ($value) => is_string($value) ? str_word_count($value) : 0);
    }
}
