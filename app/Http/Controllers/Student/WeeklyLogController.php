<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Student\Concerns\ResolvesStudentEnrollment;
use App\Http\Requests\Student\StoreWeeklyLogRequest;
use App\Models\JournalEntry;
use App\Models\WeeklyLog;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
            'issues_concerns' => $log->issues_concerns ?? '',
            'solutions' => $log->solutions ?? '',
            'recommendations' => $log->recommendations ?? '',
            'daily_entries' => $dailyEntries,
        ]);
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

        // TODO: supervisor review (approve/return) is out of scope for this task.
        $log = WeeklyLog::updateOrCreate(
            ['student_id' => $user->id, 'batch_id' => $enrollment->batch_id, 'week_start' => $start->toDateString()],
            [
                'week_end' => $end->toDateString(),
                'narrative' => $validated['narrative'] ?? null,
                'issues_concerns' => $validated['issues_concerns'] ?? null,
                'solutions' => $validated['solutions'] ?? null,
                'recommendations' => $validated['recommendations'] ?? null,
            ]
        );

        return response()->json($log);
    }
}
