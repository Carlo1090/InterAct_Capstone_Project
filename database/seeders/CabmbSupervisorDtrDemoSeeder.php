<?php

namespace Database\Seeders;

use App\Models\BatchStudent;
use App\Models\CompanyGeofence;
use App\Models\DtrSession;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Clock-in sites and banked time records for mdcbalsup, so the supervisor's
 * Daily Time Record page, the coordinator's DTR monitor and the student hours
 * gauge all have something real in them.
 *
 * REVERSES A PREVIOUSLY DOCUMENTED DECISION, deliberately and at the project
 * owner's request (2026-08-28). PROJECT.md used to say no geofence is ever
 * seeded, because a seeded fence is anchored to coordinates nobody is standing
 * at, so it cannot be used to actually clock in. That reasoning still holds
 * and is unchanged — **these sites are for looking at, not for scanning**. It
 * simply is not the whole story: the site LIST is a real surface with real
 * actions on it (resize, retire, restore, delete), and with zero sites seeded
 * none of them could be seen at all, including the retire/restore/delete flow
 * this seeder exists to demonstrate.
 *
 * Anyone testing a real punch still has to create their own site from their
 * own location. Nothing about that changed.
 *
 * The three sites are chosen to show the whole lifecycle at once:
 *
 *   "Main Branch — Front Entrance"  active, clean capture, HAS time records
 *   "Vault Annex (2F)"              retired WITH records → can only be restored
 *   "Test — do not use"             retired with ZERO records → deletable
 *
 * That last one is the case permanent delete exists for: a site created by
 * mistake. It is also the only one where the Delete permanently button
 * appears, so the guard is visible as a difference between two rows rather
 * than something you have to read the code to know about.
 *
 * Sessions: `closed` days that bank real hours against the batch's 486, one
 * `flagged` row (the auto time-out shape — no time_out, an assumed 8h that
 * counts zero) so the supervisor's "Needs attention" tab is non-empty, and one
 * `void` row so that filter is too.
 *
 * NOTE — DatabaseSeeder runs WithoutModelEvents, so `CompanyGeofence::booted()`
 * (which generates `token`) and `DtrSession::booted()` (which keeps
 * `open_session_key` in step with `status`) are BOTH muted here. Every value
 * they would normally supply is therefore written explicitly below. Dropping
 * either one breaks the seed: `token` is NOT NULL and unique, and a wrong
 * `open_session_key` would either violate the one-open-session index or leave
 * a closed session holding a student's key forever.
 *
 * Re-runnable: sites are keyed on (company, label) and sessions on
 * (student, work_date) with whereDate().
 */
class CabmbSupervisorDtrDemoSeeder extends Seeder
{
    /** Roughly the company's registered address on CPG Avenue, Tagbilaran. */
    private const LAT = 9.6496200;

    private const LNG = 123.8535400;

    private const WORKING_DAYS = 12;

    private const SHIFT_MINUTES = 480;

    public function run(): void
    {
        $supervisor = User::where('username', 'mdcbalsup')->first();

        if (! $supervisor) {
            return;
        }

        $enrollments = BatchStudent::where('supervisor_id', $supervisor->id)
            ->where('status', 'active')
            ->orderBy('student_id')
            ->get();

        if ($enrollments->isEmpty()) {
            return;
        }

        $companyId = (int) $enrollments->first()->company_id;

        $main = $this->site($companyId, $supervisor, 'Main Branch — Front Entrance', 0.0, 0.0, 150, 18, true);
        $annex = $this->site($companyId, $supervisor, 'Vault Annex (2F)', 0.0004, -0.0003, 100, 64, false);
        // Zero sessions are ever attached to this one — that is the point.
        $this->site($companyId, $supervisor, 'Test — do not use', -0.0021, 0.0018, 500, 140, false);

        foreach ($enrollments as $position => $enrollment) {
            $this->seedSessions($enrollment, $main, $annex, (int) $position, $supervisor);
        }
    }

    private function site(
        int $companyId,
        User $creator,
        string $label,
        float $latOffset,
        float $lngOffset,
        int $radius,
        ?int $accuracy,
        bool $isActive,
    ): CompanyGeofence {
        $geofence = CompanyGeofence::where('company_id', $companyId)->where('label', $label)->first();

        $attributes = [
            'created_by' => $creator->id,
            'latitude' => self::LAT + $latOffset,
            'longitude' => self::LNG + $lngOffset,
            'radius_meters' => $radius,
            'captured_accuracy' => $accuracy,
            'is_active' => $isActive,
        ];

        if ($geofence) {
            $geofence->fill($attributes)->save();

            return $geofence;
        }

        $geofence = new CompanyGeofence($attributes);
        $geofence->company_id = $companyId;
        $geofence->label = $label;
        // The creating hook that would do this is muted by WithoutModelEvents.
        $geofence->token = Str::random(32);
        $geofence->save();

        return $geofence;
    }

    /**
     * A wall-clock time as every MDC user experiences it, converted to
     * whatever timezone the app is configured for.
     *
     * NOT `$day->setTime(8, 0)`. That writes 08:00 in the APP's timezone, and
     * `config('app.timezone')` defaults to UTC — deployments set Asia/Manila,
     * local dev usually does not. On a UTC box an 08:00 seed is 08:00Z, which
     * the SPA renders in the viewer's own timezone as 4:00 PM: a morning shift
     * that reads as an afternoon one, and a submitted-at that lands on the
     * following day. Anchoring to Asia/Manila is correct under BOTH configs,
     * because it describes the moment rather than a number on a clock.
     */
    private function manila(Carbon $day, int $hour, int $minute): Carbon
    {
        return Carbon::create(
            $day->year,
            $day->month,
            $day->day,
            $hour,
            $minute,
            0,
            'Asia/Manila'
        )->setTimezone(config('app.timezone'));
    }

    private function seedSessions(
        BatchStudent $enrollment,
        CompanyGeofence $main,
        CompanyGeofence $annex,
        int $position,
        User $supervisor,
    ): void {
        $day = Carbon::today();
        $seeded = 0;

        while ($seeded < self::WORKING_DAYS) {
            $day = $day->copy()->subDay();

            if ($day->isWeekend()) {
                continue;
            }

            $seeded++;

            // One flagged and one void per intern, at fixed positions in the
            // run so every intern's history looks slightly different without
            // being random (a random seed would change on every re-run).
            $status = match (true) {
                $seeded === 3 + $position => 'flagged',
                $seeded === 8 => 'void',
                default => 'closed',
            };

            // A little variety in the day length, still plausible for an
            // 8-hour shift with a lunch break.
            $minutes = self::SHIFT_MINUTES - (($seeded + $position) % 3) * 15;

            $this->session($enrollment, $seeded === 6 ? $annex : $main, $day, $status, $minutes, $supervisor);
        }
    }

    private function session(
        BatchStudent $enrollment,
        CompanyGeofence $site,
        Carbon $day,
        string $status,
        int $minutes,
        User $supervisor,
    ): void {
        $timeIn = $this->manila($day, 8, 0);

        $attributes = [
            'batch_id' => $enrollment->batch_id,
            'geofence_id' => $site->id,
            'time_in' => $timeIn,
            'time_in_lat' => $site->latitude,
            'time_in_lng' => $site->longitude,
            'time_in_accuracy' => 12,
            'time_in_distance' => 20,
            // A flagged row is the auto time-out shape: nobody observed the
            // student leaving, so time_out stays NULL and the 8h is only the
            // supervisor's starting number in Adjust. COUNTED_STATUSES is
            // `closed` only, so it banks nothing until they confirm it.
            'time_out' => $status === 'flagged' ? null : $timeIn->copy()->addMinutes($minutes + 60),
            'time_out_lat' => $status === 'flagged' ? null : $site->latitude,
            'time_out_lng' => $status === 'flagged' ? null : $site->longitude,
            'time_out_accuracy' => $status === 'flagged' ? null : 14,
            'time_out_distance' => $status === 'flagged' ? null : 26,
            'minutes_worked' => $status === 'flagged' ? self::SHIFT_MINUTES : $minutes,
            'status' => $status,
            'adjustment_reason' => $status === 'void'
                ? 'Duplicate scan — the intern was already timed in at the front entrance.'
                : null,
            // A void is a human decision, so it names the human. NULL here
            // means the system did it, which is how an auto time-out stays
            // distinguishable from a supervisor's correction.
            'adjusted_by' => $status === 'void' ? $supervisor->id : null,
            // The saving hook that maintains this is muted by
            // WithoutModelEvents, so it is written by hand. Anything other
            // than NULL on a non-open session would hold that student's slot
            // in the one-open-session unique index forever.
            'open_session_key' => null,
        ];

        $existing = DtrSession::where('student_id', $enrollment->student_id)
            ->whereDate('work_date', $day->toDateString())
            ->first();

        if ($existing) {
            $existing->fill($attributes)->save();

            return;
        }

        DtrSession::create($attributes + [
            'student_id' => $enrollment->student_id,
            'work_date' => $day->toDateString(),
        ]);
    }
}
