<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Student\Concerns\ResolvesStudentEnrollment;
use App\Http\Requests\Student\UpdateReminderPreferenceRequest;
use App\Models\StudentProfile;
use App\Support\BatchWorkingDays;
use App\Support\ReminderSchedule;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The student's own daily-journal reminder "alarm": which days, what time, and
 * whether it fires at all. Read by exactly one consumer, the
 * journal:send-missing-entry-reminders command — never by anything that scores
 * compliance.
 */
class ReminderPreferenceController extends Controller
{
    use ResolvesStudentEnrollment;

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json($this->payload($this->profileFor($user->id), $user->id));
    }

    public function update(UpdateReminderPreferenceRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();
        $profile = $this->profileFor($user->id);

        if (! $profile) {
            return response()->json([
                'message' => 'Your student profile is missing. Please contact your coordinator.',
            ], 422);
        }

        $days = $validated['reminder_days'] ?? null;

        $profile->update([
            'reminder_enabled' => $validated['reminder_enabled'],
            // Store sorted + deduped so the column reads predictably and the
            // order the checkboxes happened to be clicked in never leaks in.
            'reminder_days' => $this->normalizeDays($days),
            // Normalized to H:i:s for a `time` column; null means "use the
            // batch default", which is why an empty string must become null.
            'reminder_time' => $this->normalizeTime($validated['reminder_time'] ?? null),
        ]);

        return response()->json($this->payload($profile->fresh(), $user->id));
    }

    private function profileFor(int $userId): ?StudentProfile
    {
        return StudentProfile::where('user_id', $userId)->first();
    }

    /**
     * @param  list<int>|null  $days
     */
    private function normalizeDays(?array $days): ?string
    {
        if ($days === null || $days === []) {
            return null;
        }

        $unique = collect($days)->map(fn ($day) => (int) $day)->unique()->sort()->values();

        return $unique->implode(',');
    }

    private function normalizeTime(?string $time): ?string
    {
        $raw = trim((string) $time);

        return $raw === '' ? null : Carbon::createFromFormat('H:i', $raw)->format('H:i:s');
    }

    /**
     * The student's stored preference plus the batch-derived defaults, so the UI
     * can show what "not set" actually resolves to rather than an empty control.
     *
     * @return array<string, mixed>
     */
    private function payload(?StudentProfile $profile, int $userId): array
    {
        $enrollment = $this->currentEnrollment($userId);
        $batch = $enrollment?->batch;

        $workingDaysPerWeek = $batch->working_days_per_week ?? 5;

        $defaultDays = collect(range(1, 7))
            ->filter(fn (int $iso) => BatchWorkingDays::isWorkingDay(
                Carbon::now()->startOfWeek(Carbon::MONDAY)->addDays($iso - 1),
                $workingDaysPerWeek
            ))
            ->values()
            ->all();

        $defaultHour = ReminderSchedule::hourFor(null, $batch->daily_reminder_time ?? null);

        return [
            'reminder_enabled' => $profile?->reminder_enabled ?? true,
            'reminder_days' => $profile?->reminderDayNumbers(),
            'reminder_time' => $this->displayTime($profile?->reminder_time),
            'defaults' => [
                'days' => $defaultDays,
                'time' => sprintf('%02d:00', $defaultHour),
            ],
        ];
    }

    /**
     * reminder_time is intentionally uncast (MySQL and SQLite hand it back in
     * different shapes), so normalize to H:i on the way out.
     */
    private function displayTime(mixed $time): ?string
    {
        $raw = trim((string) $time);

        if ($raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw)->format('H:i');
        } catch (\Throwable) {
            return null;
        }
    }
}
