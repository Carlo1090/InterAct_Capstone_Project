<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Concerns\BuildsWeeklyActivityLogPdf;
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
use App\Services\DtrService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

class WeeklyActivityLogController extends Controller
{
    use BuildsWeeklyActivityLogPdf;
    use ResolvesStudentEnrollment;

    public function __construct(private readonly DtrService $dtr) {}

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

        $validated = $request->validated();

        // Prefill the hours from what the DTR actually recorded for this
        // period — but only when the student left the field empty, and only
        // as a starting value. This form is a paper facsimile the supervisor
        // signs by hand, so a wrong total (a forgotten clock-out, a session
        // still awaiting adjustment) has to stay correctable before it is
        // printed. Nothing here ever overwrites a typed figure, and the DTR's
        // own sum remains the authority for OJT progress.
        if (($validated['no_of_hours'] ?? null) === null) {
            $suggested = $this->suggestedHours($user, $enrollment->batch_id, $validated['week_start'] ?? null);

            if ($suggested !== null) {
                $validated['no_of_hours'] = $suggested;
            }
        }

        $log = WeeklyActivityLog::create([
            ...$validated,
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
            'header' => $this->weeklyActivityLogHeader($weeklyActivityLog),
            // Advisory only: what the DTR recorded for this period, so the
            // student can see the two disagree and decide which is right.
            // Null when DTR is not in use for their batch.
            'dtr_hours' => $this->suggestedHours(
                $request->user(),
                $weeklyActivityLog->batch_id,
                $weeklyActivityLog->week_start?->toDateString(),
            ),
        ]);
    }

    /**
     * Hours the DTR banked inside the Mon-Sun week containing $weekStart, or
     * null when DTR does not apply to this student or the period is unknown.
     */
    private function suggestedHours(User $student, ?int $batchId, ?string $weekStart): ?float
    {
        if ($batchId === null || $weekStart === null || ! $this->dtr->appliesTo($student)) {
            return null;
        }

        $minutes = $this->dtr->minutesInWeek($student->id, $batchId, Carbon::parse($weekStart));

        return $minutes > 0 ? round($minutes / 60, 1) : null;
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

        return $this->renderWeeklyActivityLogPdf($weeklyActivityLog);
    }

    private function authorizeOwnership(WeeklyActivityLog $log, int $userId): void
    {
        abort_unless((int) $log->student_id === $userId, 403);
    }

    private function authorizeEntry(WeeklyActivityEntry $entry, WeeklyActivityLog $log): void
    {
        abort_unless((int) $entry->weekly_activity_log_id === $log->id, 404);
    }
}
