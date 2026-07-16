<?php

namespace App\Services;

use App\Models\BatchStudent;
use App\Models\CompanySupervisor;

/**
 * Shared placement logic for every path that enrolls a student — the
 * coordinator's manual Enroll form (EnrollmentController::store), accepting
 * a student's submitted info sheet (CoordinatorInfoSheetController::accept),
 * and the batch roster's Add-Intern flow (BatchRosterController::add).
 *
 * Invariant: at most ONE batch_students row per (batch, student) pair —
 * backed by a DB unique index. Any existing row for the exact pair
 * (dropped, completed, or active) is reconciled in place: company /
 * supervisor / division refreshed from the new submission and the status
 * set back to active (the BatchStudent saving hook clears completed_at).
 * Only when no row exists is a fresh active one created.
 */
class EnrollmentService
{
    public function enrollOrReactivate(
        int $batchId,
        int $studentId,
        int $companyId,
        ?int $supervisorId,
        ?string $assignedDivision = null,
        ?int $companySupervisorId = null,
    ): BatchStudent {
        // Callers that only know the login user_id (not which specific
        // company_supervisors row it is) get it resolved for free here, so
        // the manual Enroll form and the batch-roster Add-Intern flow don't
        // need to change at all.
        $companySupervisorId ??= CompanySupervisor::where('company_id', $companyId)
            ->where('user_id', $supervisorId)
            ->value('id');

        $attributes = [
            'company_id' => $companyId,
            'supervisor_id' => $supervisorId,
            'company_supervisor_id' => $companySupervisorId,
            'assigned_division' => $assignedDivision,
            'status' => 'active',
        ];

        $existing = BatchStudent::where('batch_id', $batchId)
            ->where('student_id', $studentId)
            ->first();

        if ($existing) {
            $existing->update($attributes);

            return $existing;
        }

        return BatchStudent::create([
            'batch_id' => $batchId,
            'student_id' => $studentId,
            ...$attributes,
        ]);
    }
}
