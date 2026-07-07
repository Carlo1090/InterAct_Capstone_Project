<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Student\Concerns\ResolvesStudentEnrollment;
use App\Http\Requests\Student\StoreJournalEntryRequest;
use App\Models\BatchStudent;
use App\Models\JournalEntry;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        $enrollment = $this->activeEnrollment($user->id);

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
            'word_limit' => $enrollment->batch->journalTemplate?->word_limit ?? 500,
            'status' => $entry->status ?? 'draft',
            'content' => $entry->content ?? [],
            'submitted_at' => $entry?->submitted_at,
            'editable' => $this->isEditableDate($entryDate, $enrollment),
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

        if (! $this->isEditableDate($entryDate, $enrollment)) {
            return response()->json(['message' => 'This date is outside your OJT range or is a future date.'], 422);
        }

        $entry = JournalEntry::updateOrCreate(
            ['student_id' => $user->id, 'entry_date' => $entryDate->toDateString()],
            [
                'batch_id' => $enrollment->batch_id,
                'content' => $validated['content'],
                'status' => $validated['status'],
                'submitted_at' => $validated['status'] === 'submitted' ? now() : null,
            ]
        );

        return response()->json($entry);
    }

    private function isEditableDate(Carbon $date, BatchStudent $enrollment): bool
    {
        if ($date->isAfter(today())) {
            return false;
        }

        $range = $this->ojtRange($enrollment);

        return $date->between($range['start'], $range['end']);
    }

    private function wordCount(array $content): int
    {
        return collect($content)->sum(fn ($value) => is_string($value) ? str_word_count($value) : 0);
    }
}
