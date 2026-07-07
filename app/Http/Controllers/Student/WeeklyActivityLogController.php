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
        ]);

        return $pdf->download("weekly-activity-log-{$weeklyActivityLog->id}.pdf");
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

        return [
            'student_name' => $user->name,
            'program' => $user->program?->name,
            'year_level' => $profile?->year_level,
            'coordinator_name' => $enrollment?->batch?->coordinator?->name,
            'company_name' => $enrollment?->company?->name,
            'supervisor_name' => $enrollment?->supervisor?->name,
        ];
    }
}
