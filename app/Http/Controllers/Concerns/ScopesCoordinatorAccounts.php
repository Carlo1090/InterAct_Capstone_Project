<?php

namespace App\Http\Controllers\Concerns;

use App\Models\BatchStudent;
use App\Models\Company;
use App\Models\CompanySupervisor;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The single answer to "which accounts is this coordinator allowed to touch?".
 *
 * A coordinator reaches two populations, and they are scoped by two different
 * rules — students by PROGRAM (users.program_id inside coordinatorProgramIds())
 * and supervisors by COMPANY (users has no creator column, so a
 * coordinator-created supervisor is only discoverable through the company they
 * were attached to on creation). Both rules already existed, spread across
 * EnrollmentController and CoordinatorCompanyController as two byte-identical
 * private scopedCompanyIds() methods whose docblock said the one mirrored the
 * other. The Credential Manager needs the same two answers, and a third copy is
 * how they would finally drift — same reasoning as EnrollmentService and
 * WeeklyBundlingService::compileFor().
 */
trait ScopesCoordinatorAccounts
{
    /**
     * Company IDs a coordinator may see: those referenced by enrollments whose
     * batch program is in their scope, unioned with companies not yet linked to
     * any enrollment (no creator column exists, so unlinked implies visible —
     * which is what keeps a freshly-created company in view).
     */
    protected function scopedCompanyIds(User $coordinator): Collection
    {
        $programIds = $coordinator->coordinatorProgramIds();

        $usedIds = BatchStudent::whereHas('batch', fn ($query) => $query->whereIn('program_id', $programIds))
            ->pluck('company_id')
            ->filter()
            ->unique();

        $unlinkedIds = Company::whereDoesntHave('batchStudents')->pluck('id');

        return $usedIds->merge($unlinkedIds)->unique()->values();
    }

    /**
     * User IDs of every supervisor login attached to a company in scope.
     * Named-only company_supervisors rows carry no user_id and are skipped,
     * exactly as the Users → Supervisors tab already skips them.
     */
    protected function scopedSupervisorIds(User $coordinator): Collection
    {
        return CompanySupervisor::whereIn('company_id', $this->scopedCompanyIds($coordinator))
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->unique()
            ->values();
    }

    /**
     * Supervisor IDs a coordinator may ATTACH to one of their own companies:
     * already on a company in scope, OR attached to no company at all yet (a
     * "floating" account — freshly created, or just detached — that belongs to
     * nobody and is fair game for anyone to claim). Excludes only a supervisor
     * exclusively tied to an out-of-scope company, which is the actual leak
     * this method exists to close — scopedSupervisorIds() alone can't be used
     * for this, since it would also wrongly exclude every floating supervisor.
     */
    protected function attachableSupervisorIds(User $coordinator): Collection
    {
        $scoped = $this->scopedSupervisorIds($coordinator);

        $attachedIds = CompanySupervisor::whereNotNull('user_id')->pluck('user_id')->unique();
        $floatingIds = User::where('role', 'supervisor')->whereNotIn('id', $attachedIds)->pluck('id');

        return $scoped->merge($floatingIds)->unique()->values();
    }

    /**
     * Guard for any per-account action a coordinator takes. 404 for a role they
     * never manage (they may create students and supervisors only — never a
     * coordinator or admin), 403 for one outside their scope.
     */
    protected function authorizeManagedAccount(User $coordinator, User $account): void
    {
        abort_unless(in_array($account->role, ['student', 'supervisor'], true), 404);

        $inScope = $account->role === 'student'
            ? $coordinator->coordinatorProgramIds()->contains($account->program_id)
            : $this->scopedSupervisorIds($coordinator)->contains($account->id);

        abort_unless(
            $inScope,
            403,
            $account->role === 'student'
                ? 'That student is outside your assigned department(s).'
                : 'That supervisor is not attached to any of your companies.'
        );
    }
}
