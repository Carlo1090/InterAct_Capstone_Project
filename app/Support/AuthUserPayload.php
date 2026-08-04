<?php

namespace App\Support;

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
        }

        return $user;
    }
}
