<?php

namespace App\Http\Controllers\Supervisor\Concerns;

use App\Models\BatchStudent;
use App\Models\User;
use App\Models\WeeklyLog;
use Illuminate\Support\Collection;

trait ScopesSupervisorWork
{
    /**
     * Student IDs enrolled with this supervisor (batch_students.supervisor_id) —
     * the authoritative "my interns" scope per the locked decision.
     *
     * @return Collection<int, int>
     */
    protected function supervisedStudentIds(User $supervisor): Collection
    {
        return BatchStudent::where('supervisor_id', $supervisor->id)
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
