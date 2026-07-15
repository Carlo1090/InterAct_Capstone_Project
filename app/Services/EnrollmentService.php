<?php

namespace App\Services;

use App\Models\BatchStudent;

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
    ): BatchStudent {
        $existing = BatchStudent::where('batch_id', $batchId)
            ->where('student_id', $studentId)
            ->first();

        if ($existing) {
            $existing->update([
                'company_id' => $companyId,
                'supervisor_id' => $supervisorId,
                'assigned_division' => $assignedDivision,
                'status' => 'active',
            ]);

            return $existing;
        }

        return BatchStudent::create([
            'batch_id' => $batchId,
            'student_id' => $studentId,
            'company_id' => $companyId,
            'supervisor_id' => $supervisorId,
            'assigned_division' => $assignedDivision,
            'status' => 'active',
        ]);
    }
}
