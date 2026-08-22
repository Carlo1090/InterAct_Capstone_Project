<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Requests\Student\PunchDtrRequest;
use App\Models\DtrSession;
use App\Models\SystemLog;
use App\Services\DtrService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The student's side of the Daily Time Record.
 *
 * There is deliberately no standalone "clock out" action. A punch always
 * requires a scanned site token plus coordinates, so both ends of a shift are
 * evidenced the same way; a button that closed a session without a scan could
 * be pressed from anywhere. A student who genuinely cannot scan out asks the
 * supervisor to adjust the session, which leaves a signed reason on the
 * record.
 */
class DtrController extends Controller
{
    public function __construct(private readonly DtrService $dtr) {}

    /**
     * Everything the DTR page renders: whether the feature applies at all,
     * the currently open session if any, this week's punches, and hours
     * banked against hours required.
     */
    public function show(Request $request): JsonResponse
    {
        $student = $request->user();
        // Read scope (active OR completed): a graduated intern keeps access to
        // the record of hours they actually worked, exactly as they keep read
        // access to their journals. Punching still requires an active row.
        $enrollment = $this->dtr->readEnrollmentFor($student);

        if (! $enrollment) {
            return response()->json(['message' => 'You are not currently enrolled in an active OJT batch.'], 422);
        }

        $progress = $this->dtr->progressFor($enrollment);

        if ($progress === null) {
            // Not an error — this coordinator's programme simply does not use
            // a location-anchored DTR. The SPA hides the nav item for these
            // students, so this is only reached by a direct URL.
            return response()->json([
                'enabled' => false,
                'message' => 'Daily Time Record is not enabled for your batch.',
            ]);
        }

        $weekStart = now()->startOfWeek();

        $sessions = DtrSession::with('geofence:id,label,company_id')
            ->where('student_id', $student->id)
            ->where('batch_id', $enrollment->batch_id)
            ->whereDate('work_date', '>=', $weekStart->toDateString())
            ->whereDate('work_date', '<=', $weekStart->copy()->addDays(6)->toDateString())
            ->orderByDesc('time_in')
            ->get()
            ->map(fn (DtrSession $session) => $this->presentSession($session));

        $open = $this->dtr->openSessionFor($student);

        return response()->json([
            'enabled' => true,
            'company' => $enrollment->company?->name,
            'open_session' => $open ? $this->presentSession($open) : null,
            'week' => [
                'start' => $weekStart->toDateString(),
                'end' => $weekStart->copy()->addDays(6)->toDateString(),
                'minutes' => $this->dtr->minutesInWeek($student->id, $enrollment->batch_id, $weekStart),
            ],
            'sessions' => $sessions,
            'progress' => $progress,
        ]);
    }

    /**
     * The QR landing preview: "you are about to clock in at X". Deliberately
     * does not take coordinates — the SPA calls this to render a confirmation
     * before asking the browser for location, so a student who scanned the
     * wrong code finds out before granting a location permission.
     */
    public function resolve(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'site_token' => ['required', 'string', 'size:32'],
        ]);

        $student = $request->user();
        $enrollment = $this->dtr->requireEnrollment($student);
        $geofence = $this->dtr->resolveGeofence($validated['site_token'], $enrollment);

        $open = $this->dtr->openSessionFor($student);

        return response()->json([
            // WHO this punch would be recorded against.
            //
            // The QR opens in whichever browser the phone treats as default,
            // which on a shared or borrowed handset may already hold someone
            // else's session. Without this the punch lands on the wrong
            // student's record silently and undetectably — the scan looks
            // identical either way. Naming the account is what makes a
            // mismatch visible before anything is written.
            //
            // Free: enrollmentQuery() already eager-loads student.studentProfile.
            'student' => [
                'name' => $student->name,
                'username' => $student->username,
                'student_id_number' => $enrollment->student?->studentProfile?->student_id_number,
            ],
            'site' => [
                'label' => $geofence->label,
                'company' => $geofence->company?->name ?? $enrollment->company?->name,
                'radius_meters' => $geofence->radius_meters,
            ],
            // What punch() would do with this token right now, so the page can
            // label its button honestly before anything is written.
            'next_action' => $open === null
                ? 'clock_in'
                : ($open->geofence_id === $geofence->id ? 'clock_out' : 'blocked_other_site'),
            'open_session' => $open ? $this->presentSession($open) : null,
        ]);
    }

    /**
     * Clock in, or clock out of an open session at the same site. Every rule
     * (enrollment, DTR enabled, company match, rotation code, distance,
     * one-open-session) lives in DtrService so this stays a thin edge.
     */
    public function punch(PunchDtrRequest $request): JsonResponse
    {
        $student = $request->user();
        $validated = $request->validated();

        $result = $this->dtr->punch(
            $student,
            $validated['site_token'],
            (float) $validated['latitude'],
            (float) $validated['longitude'],
            isset($validated['accuracy']) ? (int) $validated['accuracy'] : null,
            $validated['code'] ?? null,
        );

        $session = $result['session'];
        $action = $result['action'] === 'clocked_in' ? 'Clocked In' : 'Clocked Out';

        SystemLog::record(
            "DTR {$action}",
            "{$action} at {$session->geofence?->label} ({$result['distance']}m from the registered point)."
        );

        return response()->json([
            'action' => $result['action'],
            'distance_meters' => $result['distance'],
            'session' => $this->presentSession($session->fresh(['geofence'])),
            // The receipt names the account, so a student who taps straight
            // through the confirmation still finds out if the phone was signed
            // in as someone else.
            'student_name' => $student->name,
            // True when a forgotten session from a previous day was closed to
            // make way for this clock-in. The student must be told: otherwise
            // the day they thought they banked silently became a flagged row
            // awaiting their supervisor.
            'auto_closed_previous' => $result['auto_closed_previous'],
            'message' => $result['action'] === 'clocked_in'
                ? ($result['auto_closed_previous']
                    ? 'Clocked in. Your previous session was closed automatically because no clock-out was scanned — ask your supervisor to confirm those hours.'
                    : 'Clocked in. Scan the same code again when you leave.')
                : 'Clocked out. Your hours have been recorded.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentSession(DtrSession $session): array
    {
        return [
            'id' => $session->id,
            'work_date' => $session->work_date?->toDateString(),
            'site' => $session->geofence?->label,
            'time_in' => $session->time_in?->toIso8601String(),
            'time_out' => $session->time_out?->toIso8601String(),
            'minutes_worked' => $session->minutes_worked,
            'status' => $session->status,
            // Surfaced so a student can see WHY a session is not counting
            // toward their hours rather than silently wondering.
            'adjustment_reason' => $session->adjustment_reason,
        ];
    }
}
