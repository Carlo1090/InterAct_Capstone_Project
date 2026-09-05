<?php

namespace App\Support;

use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\User;

/**
 * Builds the single authenticated-user payload the SPA consumes.
 *
 * Two endpoints hand the SPA a user — GET /api/user and POST /login — and they
 * MUST return the same shape, or the frontend gets a subtly different user
 * depending on how it authenticated. Before this class the login response was
 * missing program.department, student_gated and student_paused, which is why
 * the SPA threw its body away and re-fetched /api/user, paying a third blocking
 * round trip on every login. Both endpoints now call build() instead.
 */
class AuthUserPayload
{
    /**
     * Hydrate $user with everything the SPA's AuthUser type expects.
     *
     * Returns the same instance it was given (mutated), so callers can hand it
     * straight to response()->json().
     */
    public static function build(User $user): User
    {
        // Only load the relation when there is something to load. Admins and
        // supervisors have no program_id, so an unconditional load() spent two
        // queries on every one of their requests to arrive back at null.
        if ($user->program_id !== null) {
            $user->load('program.department');
        }

        if ($user->isStudent()) {
            // Both flags derive from the SAME two existence checks, so they are
            // resolved once here rather than through User::isInfoSheetGated()
            // and User::isEnrollmentPaused() — the latter calls the former
            // internally, re-running both queries for a total of four.
            $hasApprovedSheet = $user->studentInformationSheets()
                ->where('submission_status', 'approved')
                ->exists();

            $hasQualifyingEnrollment = BatchStudent::where('student_id', $user->id)
                ->whereIn('status', ['active', 'completed'])
                ->exists();

            // Still in intake. Mirrors User::isInfoSheetGated(): an approved
            // sheet OR a legacy direct enrollment both prove intake is cleared.
            $user->setAttribute('student_gated', ! $hasApprovedSheet && ! $hasQualifyingEnrollment);

            // Past intake but dropped from their batch. Mirrors
            // User::isEnrollmentPaused(), which reads
            // "! isInfoSheetGated() && ! hasQualifyingEnrollment" — expand that
            // to (approved || enrolled) && !enrolled and it reduces to exactly
            // the line below. Same answer, half the queries.
            $user->setAttribute('student_paused', $hasApprovedSheet && ! $hasQualifyingEnrollment);

            // Whether the Daily Time Record applies to this student, resolved
            // from their batch's coordinator. Needed here rather than on the
            // DTR endpoint itself because StudentLayout filters the nav before
            // any page loads — without it the item would flash in and out for
            // the students who cannot use it. One exists() query.
            // The batch condition mirrors DtrService::runsForEnrollment(): a
            // coordinator-centered batch has no supervisor to anchor a site or
            // correct a punch, so the DTR does not run for it whatever the
            // coordinator's own preference says. Folded into the existing
            // whereHas rather than added as a second query.
            $user->setAttribute('student_dtr_enabled', BatchStudent::where('student_id', $user->id)
                ->where('status', 'active')
                ->whereHas('batch', fn ($query) => $query
                    ->where('ojt_type', Batch::OJT_TYPE_SUPERVISOR)
                    ->whereHas('coordinator', fn ($inner) => $inner->where('dtr_enabled', true)))
                ->exists());
        }

        if ($user->isCoordinator()) {
            // Whether this coordinator has any cohort they personally review.
            // Needed on the payload rather than on the page itself for the same
            // reason as `student_dtr_enabled` above: CoordinatorLayout filters
            // the nav before any page loads, so without it the Journal Review
            // item would flash in and then vanish for every coordinator running
            // only supervisor-supported batches.
            //
            // Hiding it is a DELIBERATE reversal of the original decision (the
            // item used to be shown to everyone, with the page's empty state
            // explaining how to get a batch here) made 2026-09-01 at the
            // project owner's request: a permanent dead end on the sidebar is a
            // worse cost than the lost discoverability, and the OJT Type
            // control on the Batches page is where the feature is genuinely
            // discovered anyway. One exists() query, on a cached program list.
            // Both flags in ONE query rather than two exists() round trips —
            // they ask the same question of the same rows, only about a
            // different value of the column.
            $ojtTypes = Batch::whereIn('program_id', $user->coordinatorProgramIds())
                ->distinct()
                ->pluck('ojt_type');

            $user->setAttribute(
                'coordinator_has_centered_batch',
                $ojtTypes->contains(Batch::OJT_TYPE_COORDINATOR)
            );

            // Whether the Daily Time Record can apply to anybody this
            // coordinator runs. It is the OPPOSITE type to the flag above, not
            // the same one: DtrService::runsForEnrollment() requires a
            // SUPERVISOR-SUPPORTED batch, because the whole scheme rests on a
            // company supervisor being on site — only they can anchor a
            // geofence at the workplace or vouch for a forgotten punch. A
            // coordinator whose cohorts are all coordinator-centered has no
            // intern who can clock in at all (the project owner's own client is
            // exactly this case and has confirmed the DTR does not apply to
            // them), so their DTR page is a permanently empty table.
            //
            // Deliberately NOT also gated on `dtr_enabled`: /coordinator/dtr is
            // the ONE place that switch is set, so hiding the page whenever the
            // DTR is off would make switching it off irreversible.
            $user->setAttribute(
                'coordinator_has_supervised_batch',
                $ojtTypes->contains(Batch::OJT_TYPE_SUPERVISOR)
            );
        }

        return $user;
    }
}
