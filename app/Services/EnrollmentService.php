<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\Company;
use App\Models\CompanySupervisor;
use App\Models\StudentInformationSheet;
use App\Models\User;

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
 *
 * The supervisor is never a caller-supplied choice — it's tied to the
 * company, not a specific person picked independently. Every caller only
 * supplies a company_id; the company's one login supervisor
 * (Company::loginSupervisor()) is resolved here, in one place, so the rule
 * can't drift between the manual Enroll form, the batch roster's Add-Intern
 * flow, and info-sheet Accept.
 *
 * Since batches carry an `ojt_type`, that resolution has two branches — and
 * they are BOTH here, for the same reason. A coordinator-centered batch has no
 * company supervisor to resolve, so the enrollment is written with a null
 * supervisor_id and the "add a supervisor first" 422 never fires; a
 * supervisor-supported batch behaves exactly as it always has. The branch is
 * read off the batch, never off a parameter, so no caller can opt a placement
 * out of the gate by forgetting to pass something.
 */
class EnrollmentService
{
    public function enrollOrReactivate(
        int $batchId,
        int $studentId,
        int $companyId,
        ?string $assignedDivision = null,
        ?int $companySupervisorId = null,
    ): BatchStudent {
        $company = Company::findOrFail($companyId);

        // A coordinator-centered batch has no company supervisor at all, so
        // there is nothing to resolve and nothing to refuse: the enrollment is
        // placed with a null supervisor and the coordinator reviews the weekly
        // journals themselves. Resolving the gate from the BATCH (not from a
        // caller-supplied flag) is what keeps the manual Enroll form, the
        // roster's Add-Intern flow and info-sheet Accept from drifting — the
        // same reason the supervisor is derived here rather than passed in.
        $batch = Batch::findOrFail($batchId);

        if ($batch->isCoordinatorCentered()) {
            $supervisorId = null;
            $companySupervisorId = null;
        } else {
            $supervisorId = $company->loginSupervisor?->user_id;
            abort_if($supervisorId === null, 422, 'This company has no supervisor account yet. Add one to the company before enrolling a student.');

            // Callers that only know the company (not which specific
            // company_supervisors row is the named individual) get it resolved
            // for free here.
            $companySupervisorId ??= CompanySupervisor::where('company_id', $companyId)
                ->where('user_id', $supervisorId)
                ->value('id');
        }

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

    /**
     * Record a coordinator's intended placement as a DRAFT info sheet whose
     * batch_id is the intended batch — the single home for "intended batch
     * before Accept". Program/department/coordinator are pre-filled from the
     * batch so the student's gated info-sheet form opens partly populated;
     * the student supplies the rest and chooses their company from the
     * dropdown. Shared by the manual create-account flow
     * (EnrollmentController::createAccount) and bulk import
     * (BulkStudentImportController) so both seed the sheet identically.
     *
     * @param  array{first_name: string, middle_name: ?string, last_name: string, sex?: ?string}  $name
     */
    public function scaffoldIntendedSheet(User $student, Batch $batch, array $name): void
    {
        $batch->loadMissing(['program.department', 'coordinator']);

        StudentInformationSheet::create([
            'student_id' => $student->id,
            'batch_id' => $batch->id,
            'submission_status' => 'draft',
            'personal_info' => [
                // Stored from the discrete First/Middle/Family fields the
                // coordinator typed (or the bulk-import sheet carried) —
                // never re-split from the joined users.name (which would
                // fold the middle name into the family name).
                'last_name' => $name['last_name'],
                'first_name' => $name['first_name'],
                'middle_name' => $name['middle_name'] ?? null,
                'student_id_number' => $student->student_id_number,
                // Only bulk import supplies this today — the manual flow
                // doesn't collect sex, so it stays null and the student
                // fills it in themselves, unchanged from before.
                'sex' => $name['sex'] ?? null,
            ],
            'academic_info' => [
                'program_course' => $batch->program?->name,
                'department' => $batch->program?->department?->name,
                'internship_coordinator' => $batch->coordinator?->name,
            ],
            'ojt_info' => [],
            'emergency_contact' => null,
        ]);
    }
}
