<?php

namespace App\Services;

use App\Models\BatchStudent;
use App\Models\CompanyGeofence;
use App\Models\DtrSession;
use App\Models\User;
use App\Support\GeoDistance;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Every DTR rule lives here, for the same reason EnrollmentService exists:
 * the clock-in path is reached from a QR landing page, the student's DTR page
 * and (eventually) the mobile app, and the geofence rule must not be able to
 * drift between them.
 *
 * A punch is a TOGGLE against a scanned QR code, not two independent
 * endpoints. Scanning on arrival opens a session; scanning the same code on
 * departure closes it. That mirrors the physical act, and it closes the
 * obvious hole in a standalone "clock out" button, which a student could
 * press from anywhere.
 *
 * What this does and does not prove is worth stating plainly: the QR payload
 * is static and therefore copyable, so the QR identifies a SITE and proves
 * nothing about presence. The geofence carries the entire anti-fraud load,
 * and browser geolocation is client-supplied and spoofable. Every punch
 * therefore records coordinates, reported accuracy and computed distance, so
 * the supervisor reviewing the period has something auditable rather than a
 * bare timestamp.
 */
class DtrService
{
    /**
     * A session open longer than this is a forgotten clock-out, not a real
     * shift, and is closed automatically as 'flagged' — so its minutes do NOT
     * count toward required hours until a supervisor has looked at it.
     *
     * 12 hours, not 8: closing at exactly one standard shift would punish a
     * genuinely long day. An intern working 8h30m would find their session
     * already closed, and their scan-out would then read as a fresh clock-in.
     * 12h clears a standard shift plus lunch plus grace, still closes a morning
     * clock-in the same evening, and leaves a real shift that crosses midnight
     * (in at 22:00, out at 02:00) untouched — which a "crossed a date" rule
     * would not.
     */
    public const STALE_SESSION_MINUTES = 720; // 12 hours

    /**
     * The standard OJT shift, banked on an auto-closed session as the
     * SUPERVISOR'S STARTING NUMBER — never as credit.
     *
     * Safe to write because such a session is 'flagged', and
     * DtrSession::COUNTED_STATUSES is 'closed' only, so it contributes exactly
     * zero to banked hours until a supervisor confirms it through adjust().
     */
    public const DEFAULT_SHIFT_MINUTES = 480; // 8 hours

    /** minutes_worked is an unsignedSmallInteger. */
    private const MINUTES_COLUMN_CEILING = 65535;

    /**
     * Whether DTR applies to this student at all — resolved from their
     * batch's coordinator, who sets the preference during account setup. A
     * coordinator whose interns have no fixed workplace leaves it off and
     * their students never see the feature.
     *
     * Read-scoped: a graduated intern's hours are still their hours.
     */
    public function appliesTo(User $student): bool
    {
        return $this->runsForEnrollment($this->readEnrollmentFor($student));
    }

    /**
     * The single answer to "does the Daily Time Record run for this
     * enrollment?", used by every gate below so they cannot drift.
     *
     * TWO conditions, and the second is not the coordinator's preference:
     *
     * 1. The batch's coordinator has the DTR switched on.
     * 2. The batch is supervisor-supported.
     *
     * A coordinator-centered batch has no company supervisor at all, and a
     * supervisor is the only person who can anchor a geofence at the workplace
     * or vouch for a forgotten punch by adjusting it. Offering QR clock-in
     * there would produce records nobody on site could correct, so hours come
     * from the typed Weekly and Time Log Summary instead — and, as everywhere
     * else in this service, an unclocked hour stays unclocked rather than
     * being invented.
     */
    public function runsForEnrollment(?BatchStudent $enrollment): bool
    {
        return (bool) $enrollment?->batch?->coordinator?->dtr_enabled
            && (bool) $enrollment?->batch?->isSupervisorSupported();
    }

    /**
     * The enrollment that permits PUNCHING — active only, matching
     * ResolvesStudentEnrollment::activeEnrollment(). A completed student's
     * OJT is over; they cannot clock in again.
     */
    public function enrollmentFor(User $student): ?BatchStudent
    {
        return $this->enrollmentQuery($student)
            ->where('status', 'active')
            ->first();
    }

    /**
     * The enrollment that permits READING the time record — active OR
     * completed, mirroring ResolvesStudentEnrollment::currentEnrollment().
     *
     * The split is the project's standing rule, and getting it wrong here was
     * a real inconsistency: StudentDashboardController resolves through
     * currentEnrollment() and so already showed a completed student their
     * hours, while the DTR page that explains that number 422'd them out.
     */
    public function readEnrollmentFor(User $student): ?BatchStudent
    {
        return $this->enrollmentQuery($student)
            ->whereIn('status', ['active', 'completed'])
            ->first();
    }

    /**
     * @return Builder<BatchStudent>
     */
    private function enrollmentQuery(User $student): Builder
    {
        return BatchStudent::with(['batch.coordinator', 'company', 'student.studentProfile'])
            ->where('student_id', $student->id)
            ->latest('enrolled_at');
    }

    /**
     * 422 unless this student is on an active enrollment whose coordinator
     * has DTR switched on. Returns the enrollment so callers do not resolve
     * it twice.
     */
    public function requireEnrollment(User $student): BatchStudent
    {
        $enrollment = $this->enrollmentFor($student);

        abort_if($enrollment === null, 422, 'You are not currently enrolled in an active OJT batch.');
        abort_unless(
            $this->runsForEnrollment($enrollment),
            403,
            'Daily Time Record is not enabled for your batch.'
        );

        return $enrollment;
    }

    /**
     * Resolve a scanned QR payload to a live site, and confirm it belongs to
     * the company the student is actually placed at — a student cannot clock
     * in at someone else's host establishment.
     */
    public function resolveGeofence(string $token, BatchStudent $enrollment): CompanyGeofence
    {
        $geofence = CompanyGeofence::where('token', $token)->where('is_active', true)->first();

        abort_if($geofence === null, 404, 'That QR code is not recognised. Ask your supervisor for the current one.');
        abort_unless(
            $geofence->company_id === $enrollment->company_id,
            403,
            'That QR code belongs to a different company than the one you are placed at.'
        );

        return $geofence;
    }

    public function openSessionFor(User $student): ?DtrSession
    {
        return DtrSession::with('geofence.company')
            ->where('student_id', $student->id)
            ->where('status', 'open')
            ->latest('time_in')
            ->first();
    }

    /**
     * Clock in, or clock out of an already-open session at the same site.
     *
     * @param  string|null  $code  Rotating TOTP code. Accepted and ignored while
     *                             every geofence is static; see verifyRotatingCode().
     * @return array{session: DtrSession, action: string, distance: int, auto_closed_previous: bool}
     */
    public function punch(
        User $student,
        string $token,
        float $latitude,
        float $longitude,
        ?int $accuracy = null,
        ?string $code = null,
    ): array {
        $enrollment = $this->requireEnrollment($student);
        $geofence = $this->resolveGeofence($token, $enrollment);

        $this->verifyRotatingCode($geofence, $code);

        $distance = (int) round(GeoDistance::metresBetween(
            $latitude,
            $longitude,
            $geofence->latitude,
            $geofence->longitude,
        ));

        abort_if(
            $distance > $geofence->radius_meters,
            422,
            "You appear to be about {$distance}m from {$geofence->label}, outside the {$geofence->radius_meters}m allowed range. Move closer and try again."
        );

        $open = $this->openSessionFor($student);

        // A session left open past STALE_SESSION_MINUTES is a forgotten
        // clock-out from a previous day, NOT the shift this student is
        // starting now. Treating it as one would be the worst possible
        // reading of the scan: the student taps expecting to time in, is
        // silently timed OUT of yesterday instead, walks away believing they
        // are clocked in, and their evening scan opens another overnight
        // session — the failure cascades a day at a time.
        //
        // The nightly dtr:auto-close-sessions command normally gets here
        // first. This guard is what makes the toggle correct anyway: the API
        // sleeps on an idle free tier and the external cron jitters, so the
        // morning scan cannot depend on the job having run.
        $autoClosedPrevious = false;

        if ($open !== null && $this->isStale($open)) {
            $this->autoCloseStaleSession($open, self::staleReason());
            $open = null;
            $autoClosedPrevious = true;
        }

        if ($open === null) {
            return [
                'session' => $this->openSession($enrollment, $geofence, $latitude, $longitude, $accuracy, $distance),
                'action' => 'clocked_in',
                'distance' => $distance,
                'auto_closed_previous' => $autoClosedPrevious,
            ];
        }

        // An open session at a DIFFERENT site is not something this can
        // silently reconcile — closing it here would invent a clock-out time
        // and place the student never gave. The supervisor adjusts it.
        abort_if(
            $open->geofence_id !== $geofence->id,
            422,
            'You still have an open session at another site. Ask your supervisor to close it before clocking in here.'
        );

        return [
            'session' => $this->closeSession($open, $latitude, $longitude, $accuracy, $distance),
            'action' => 'clocked_out',
            'distance' => $distance,
            'auto_closed_previous' => false,
        ];
    }

    private function openSession(
        BatchStudent $enrollment,
        CompanyGeofence $geofence,
        float $latitude,
        float $longitude,
        ?int $accuracy,
        int $distance,
    ): DtrSession {
        // Server clock, never the client's — a device clock is as manipulable
        // as its GPS.
        $now = now();

        try {
            return $this->insertOpenSession($enrollment, $geofence, $now, $latitude, $longitude, $accuracy, $distance);
        } catch (UniqueConstraintViolationException) {
            // Lost the race: another request for this same student opened a
            // session between our openSessionFor() check and this insert (a
            // double-tap, or two tabs). dtr_sessions.open_session_key is
            // uniquely indexed precisely so the database refuses the second
            // one rather than leaving the student with two open sessions.
            //
            // Reported as success, not an error: the student's intent was "I
            // am here now", and that is exactly what the winning request
            // recorded. Surfacing a failure would invite them to tap again.
            $existing = $this->openSessionFor($enrollment->student);

            abort_if($existing === null, 422, 'Your clock-in could not be recorded. Please try again.');

            return $existing;
        }
    }

    private function insertOpenSession(
        BatchStudent $enrollment,
        CompanyGeofence $geofence,
        CarbonInterface $now,
        float $latitude,
        float $longitude,
        ?int $accuracy,
        int $distance,
    ): DtrSession {
        return DtrSession::create([
            'student_id' => $enrollment->student_id,
            'batch_id' => $enrollment->batch_id,
            'geofence_id' => $geofence->id,
            // config('app.timezone') is Asia/Manila on deployments; deriving
            // the date from the server's own "now" keeps an early-morning
            // punch on the right day.
            'work_date' => $now->copy()->toDateString(),
            'time_in' => $now,
            'time_in_lat' => $latitude,
            'time_in_lng' => $longitude,
            'time_in_accuracy' => $accuracy,
            'time_in_distance' => $distance,
            'status' => 'open',
        ]);
    }

    private function closeSession(
        DtrSession $session,
        float $latitude,
        float $longitude,
        ?int $accuracy,
        int $distance,
    ): DtrSession {
        $now = now();
        $minutes = (int) min(
            self::MINUTES_COLUMN_CEILING,
            max(0, $session->time_in->diffInMinutes($now))
        );

        $session->fill([
            'time_out' => $now,
            'time_out_lat' => $latitude,
            'time_out_lng' => $longitude,
            'time_out_accuracy' => $accuracy,
            'time_out_distance' => $distance,
            'minutes_worked' => $minutes,
            // Backstop only: the stale guard in punch() closes anything past
            // this threshold before a clock-out can ever be computed from it.
            // Kept so a caller reaching closeSession() by another route still
            // cannot bank an impossible shift.
            'status' => $minutes > self::STALE_SESSION_MINUTES ? 'flagged' : 'closed',
        ]);

        $session->save();

        return $session;
    }

    /**
     * Has this session been open long enough to be a forgotten clock-out?
     *
     * Measured from time_in rather than from work_date, deliberately: a
     * date-based rule ("it is a new day, close it") would cut short a real
     * shift that started at 22:00 and ends at 02:00, which the DTR explicitly
     * supports and which DtrPunchTest pins.
     */
    public function isStale(DtrSession $session, ?CarbonInterface $now = null): bool
    {
        if (! $session->isOpen() || $session->time_in === null) {
            return false;
        }

        return $session->time_in->diffInMinutes($now ?? now()) > self::STALE_SESSION_MINUTES;
    }

    /**
     * @return Builder<DtrSession> Every open session past the stale threshold.
     */
    public function staleOpenSessions(?CarbonInterface $now = null): Builder
    {
        return DtrSession::query()
            ->where('status', 'open')
            ->where('time_in', '<=', ($now ?? now())->copy()->subMinutes(self::STALE_SESSION_MINUTES));
    }

    /**
     * The wording written onto every auto-closed session, in one place so the
     * scheduled command and the punch-time guard cannot describe the same
     * event differently to the same student.
     */
    public static function staleReason(): string
    {
        $hours = (int) (self::STALE_SESSION_MINUTES / 60);
        $shift = (int) (self::DEFAULT_SHIFT_MINUTES / 60);

        return "Automatically timed out after {$hours} hours open — no clock-out was scanned. "
            ."{$shift} hours assumed, pending your supervisor's confirmation.";
    }

    /**
     * Close a forgotten session. The SINGLE writer both callers go through —
     * the nightly command and the guard inside punch() — for the same reason
     * EnrollmentService exists: the rule must not drift between them.
     *
     * Three deliberate choices:
     *
     * - status 'flagged', so DtrSession::COUNTED_STATUSES excludes it and the
     *   assumed hours below can never become credit on their own.
     * - minutes_worked = DEFAULT_SHIFT_MINUTES, as the supervisor's starting
     *   number in the Adjust action rather than a fact about this day.
     * - time_out stays NULL. Nobody observed the student leaving, and stamping
     *   an assumed departure would print a time the student never gave into
     *   the "Out" column of their own record. A null there reads correctly as
     *   "never scanned out", which is exactly what happened.
     *
     * adjusted_by is left null so a system close stays distinguishable from a
     * human correction.
     *
     * save() (not update()) because the DtrSession::booted() saving hook is
     * what clears open_session_key — and that is what releases the student to
     * clock in again.
     */
    public function autoCloseStaleSession(DtrSession $session, string $reason): DtrSession
    {
        $session->fill([
            'time_out' => null,
            'minutes_worked' => self::DEFAULT_SHIFT_MINUTES,
            'status' => 'flagged',
            'adjusted_by' => null,
            'adjustment_reason' => $reason,
        ]);

        $session->save();

        return $session;
    }

    /**
     * The rotation switch.
     *
     * Every geofence is static today, so rotation_secret is null and this is
     * a no-op — a submitted code is accepted and ignored. Populate
     * rotation_secret and that site starts requiring a time-based code, with
     * no change to the student's flow (point camera, allow location, tap) and
     * no change to any caller of punch().
     *
     * Worth recording, since the premise that dynamic QR "costs a fortune" is
     * a common one: a rotating code is hash_hmac over a 30-second window, the
     * same construction as Google Authenticator, and costs nothing. Paid
     * "dynamic QR" products are commercial redirect/analytics services and
     * are unrelated. The real cost of rotation is physical — the code has to
     * live on a screen, so it cannot be printed and taped to a wall.
     */
    private function verifyRotatingCode(CompanyGeofence $geofence, ?string $code): void
    {
        if (! $geofence->requiresRotatingCode()) {
            return;
        }

        abort_if($code === null, 422, 'This site uses a rotating code. Rescan the QR code currently on screen.');

        $expected = hash_hmac('sha256', (string) intdiv(now()->getTimestamp(), 30), $geofence->rotation_secret);

        abort_unless(hash_equals($expected, $code), 422, 'That code has expired. Rescan the QR code currently on screen.');
    }

    /**
     * Minutes banked toward required hours. Open sessions do not count (they
     * have no end yet), and neither do flagged or voided ones — a flagged
     * session needs a supervisor's eyes before it becomes credit.
     */
    public function minutesCompleted(int $studentId, int $batchId): int
    {
        return (int) DtrSession::where('student_id', $studentId)
            ->where('batch_id', $batchId)
            ->whereIn('status', DtrSession::COUNTED_STATUSES)
            ->sum('minutes_worked');
    }

    /**
     * The student's own total_hours_required overrides the batch default.
     * Both columns already existed; this gives them their first consumer, and
     * the per-student one becomes the transferee override it was shaped for.
     */
    public function requiredHours(BatchStudent $enrollment): ?int
    {
        $studentOverride = $enrollment->student?->studentProfile?->total_hours_required;

        return $studentOverride ?: $enrollment->batch?->required_hours;
    }

    /**
     * The hours block for the student dashboard, or null when DTR does not
     * apply — callers render their existing calendar-duration gauge in that
     * case rather than showing a zeroed-out hours gauge.
     *
     * @return array{minutes_completed: int, hours_completed: float, hours_required: ?int, hours_percent: ?int}|null
     */
    public function progressFor(BatchStudent $enrollment): ?array
    {
        if (! $this->runsForEnrollment($enrollment)) {
            return null;
        }

        $minutes = $this->minutesCompleted($enrollment->student_id, $enrollment->batch_id);
        $required = $this->requiredHours($enrollment);

        return [
            'minutes_completed' => $minutes,
            'hours_completed' => round($minutes / 60, 1),
            'hours_required' => $required,
            'hours_percent' => $required > 0
                ? max(0, min(100, (int) round(($minutes / 60) / $required * 100)))
                : null,
        ];
    }

    /**
     * Minutes banked inside a Mon-Sun week, used to prefill the SIPP weekly
     * activity log's hours field.
     */
    public function minutesInWeek(int $studentId, int $batchId, CarbonInterface $weekStart): int
    {
        $start = $weekStart->copy()->startOfWeek();

        return (int) DtrSession::where('student_id', $studentId)
            ->where('batch_id', $batchId)
            ->whereIn('status', DtrSession::COUNTED_STATUSES)
            // whereDate against a date-cast column: SQLite keeps the time
            // component MySQL truncates, so a bare whereBetween would drop
            // the last day of the range.
            ->whereDate('work_date', '>=', $start->toDateString())
            ->whereDate('work_date', '<=', $start->copy()->addDays(6)->toDateString())
            ->sum('minutes_worked');
    }
}
