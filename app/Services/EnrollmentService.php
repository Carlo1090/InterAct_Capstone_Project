<?php

namespace App\Services;

use App\Models\BatchStudent;

/**
 * Shared placement logic so the two paths that enroll a student — the
 * coordinator's manual Enroll form (EnrollmentController::store) and accepting
 * a student's submitted info sheet (CoordinatorInfoSheetController::accept) —
 * behave identically: a previously-dropped row for the same student+batch is
 * reactivated (with refreshed company/supervisor/division) rather than
 * duplicated; otherwise a fresh active row is created.
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
        $dropped = BatchStudent::where('batch_id', $batchId)
            ->where('student_id', $studentId)
            ->where('status', 'dropped')
            ->first();

        if ($dropped) {
            $dropped->update([
                'company_id' => $companyId,
                'supervisor_id' => $supervisorId,
                'assigned_division' => $assignedDivision,
                'status' => 'active',
            ]);

            return $dropped;
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
