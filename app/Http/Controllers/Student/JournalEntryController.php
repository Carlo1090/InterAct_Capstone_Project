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

        $position = $this->entryPosition($user->id, $entryDate);

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
            'entry_ordinal' => $position,
            'entry_ordinal_label' => $this->dayOrdinalLabel($position),
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

        if ($this->isBundledWeek($enrollment->student_id, $enrollment->batch_id, $entryDate)) {
            return response()->json(['message' => 'This week has already been compiled into your Weekly Log and can no longer be edited.'], 422);
        }

        if (! $this->isEditableDate($entryDate, $enrollment)) {
            return response()->json(['message' => 'This date is outside your OJT range or is a future date.'], 422);
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
            'dayLabel' => $this->dayOrdinalLabel($this->entryPosition($user->id, $entryDate)),
            'header' => $this->buildHeader($user, $enrollment),
        ]);

        return $pdf->download("daily-journal-{$entryDate->toDateString()}.pdf");
    }

    /**
     * 1-based position of this date among the student's journal entries
     * ordered by entry_date ascending (drafts included) — the "Nth Day"
     * of their OJT. For a date with no saved entry yet, this is the
     * position the entry would take once saved.
     */
    private function entryPosition(int $studentId, Carbon $entryDate): int
    {
        return JournalEntry::where('student_id', $studentId)
            ->whereDate('entry_date', '<', $entryDate->toDateString())
            ->count() + 1;
    }

    /**
     * "First Day" … "Tenth Day" as words, "Day 11" onward — the day label
     * on the document, shared verbatim by the PDF and the on-screen paper
     * view (show() exposes it as entry_ordinal_label).
     */
    private function dayOrdinalLabel(int $position): string
    {
        $words = [1 => 'First', 'Second', 'Third', 'Fourth', 'Fifth', 'Sixth', 'Seventh', 'Eighth', 'Ninth', 'Tenth'];

        return isset($words[$position]) ? "{$words[$position]} Day" : "Day {$position}";
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
     * inside the real-time window: batch start .. today, and only until
     * the week it belongs to has been bundled into a WeeklyLog.
     */
    private function isEditableDate(Carbon $date, BatchStudent $enrollment): bool
    {
        return $this->lockedReason($date, $enrollment) === null;
    }

    /**
     * True once a WeeklyLog row exists for the Mon-Fri week containing this
     * date, for this student+batch — i.e. WeeklyBundlingService has compiled
     * this week at least once (via the Saturday-midnight schedule or the
     * admin on-demand trigger). This is a one-way lock: even if the
     * resulting WeeklyLog is later returned by a supervisor for revision,
     * the underlying daily entries stay locked — corrections happen on the
     * weekly narrative itself, not by reopening daily entries.
     */
    private function isBundledWeek(int $studentId, int $batchId, Carbon $date): bool
    {
        $monday = $date->copy()->startOfWeek(Carbon::MONDAY);

        return WeeklyLog::where('student_id', $studentId)
            ->where('batch_id', $batchId)
            ->whereDate('week_start', $monday->toDateString())
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

        if ($this->isBundledWeek($enrollment->student_id, $enrollment->batch_id, $date)) {
            return 'bundled';
        }

        return null;
    }

    private function wordCount(array $content): int
    {
        return collect($content)->sum(fn ($value) => is_string($value) ? str_word_count($value) : 0);
    }
}
