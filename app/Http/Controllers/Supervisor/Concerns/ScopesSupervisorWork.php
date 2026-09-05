<?php

namespace App\Http\Controllers\Supervisor\Concerns;

use App\Models\BatchStudent;
use App\Models\CompanySupervisor;
use App\Models\User;
use App\Models\WeeklyLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

trait ScopesSupervisorWork
{
    /**
     * Companies this login is the shared "company account" for. A login is
     * attached to at most one company today, but this stays a collection in
     * case that ever changes.
     *
     * @return Collection<int, int>
     */
    protected function supervisedCompanyIds(User $supervisor): Collection
    {
        return CompanySupervisor::where('user_id', $supervisor->id)
            ->pluck('company_id')
            ->unique()
            ->values();
    }

    /**
     * Enrollments at companies this login represents — the authoritative "my
     * interns" scope. Company-based (not supervisor_id-based) so a shared
     * company login sees the company's full roster; detaching/reattaching
     * the login changes what it can see accordingly.
     *
     * The `whereNotNull('supervisor_id')` is what keeps a coordinator-centered
     * cohort off every supervisor surface, and it is deliberately ONE clause
     * here rather than an ojt_type join at each call site. A company can host
     * both kinds of batch at once; those enrollments have no supervisor at all
     * (EnrollmentService writes null for them), so "rows that actually pin a
     * supervisor" is the same question as "rows this login has any role over".
     * Because My Interns, the review queue, the per-intern notebook and the
     * DTR review surface all resolve through this one method, they cannot
     * disagree about it.
     */
    protected function supervisedEnrollments(User $supervisor): Builder
    {
        return BatchStudent::whereIn('company_id', $this->supervisedCompanyIds($supervisor))
            ->whereNotNull('supervisor_id');
    }

    /**
     * @return Collection<int, int>
     */
    protected function supervisedStudentIds(User $supervisor): Collection
    {
        return $this->supervisedEnrollments($supervisor)
            ->pluck('student_id')
            ->unique()
            ->values();
    }

    /**
     * 403 unless the weekly log belongs to one of the supervisor's interns.
     */
    protected function authorizeLog(User $supervisor, WeeklyLog $log): void
    {
        abort_unless(
            $this->supervisedStudentIds($supervisor)->contains($log->student_id),
            403,
            'This weekly log is not for one of your interns.'
        );
    }
}
