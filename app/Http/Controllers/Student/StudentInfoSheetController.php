<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Concerns\BuildsInfoSheetPdf;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Student\Concerns\ResolvesStudentEnrollment;
use App\Http\Requests\Student\StoreInfoSheetRequest;
use App\Models\Batch;
use App\Models\Company;
use App\Models\StudentInformationSheet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class StudentInfoSheetController extends Controller
{
    use BuildsInfoSheetPdf;
    use ResolvesStudentEnrollment;

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $sheet = StudentInformationSheet::where('student_id', $user->id)->latest('id')->first();

        if ($sheet) {
            return response()->json($sheet);
        }

        // Backward-compat: a student who predates the intake flow (directly
        // enrolled, no scaffolded sheet) gets a prefilled empty scaffold.
        $enrollment = $this->activeEnrollment($user->id);
        $profile = $user->studentProfile;
        [$firstName, $lastName] = array_pad(explode(' ', $user->name, 2), 2, '');

        return response()->json([
            'id' => null,
            'submission_status' => null,
            'rejection_reason' => null,
            'submitted_at' => null,
            'personal_info' => [
                'last_name' => $lastName,
                'first_name' => $firstName,
                'middle_name' => $profile?->middle_name,
                'parent_guardian_name' => null,
                'parent_guardian_contact' => null,
                'date_of_birth' => $profile?->date_of_birth?->toDateString(),
                'sex' => $profile?->sex,
                'home_address' => $profile?->home_address,
                'contact_number' => $profile?->contact_number,
                'email' => $user->email,
                'student_id_number' => $user->student_id_number,
            ],
            'academic_info' => [
                'program_course' => $user->program?->name,
                'year_level' => $profile?->year_level,
                'department' => $user->program?->department?->name,
                'internship_coordinator' => $enrollment?->batch?->coordinator?->name,
                'coordinator_contact_no' => null,
            ],
            'ojt_info' => [
                'host_company' => $enrollment?->company?->name,
                'company_address' => $enrollment?->company?->address,
                'company_signatory_moa' => null,
                'office_designation' => null,
                'supervisor_name' => $enrollment?->supervisor?->name,
                'supervisor_contact' => null,
                'area_assigned' => $enrollment?->assigned_division,
                'intern_duty_schedule' => null,
                'ojt_start_date' => $enrollment?->batch?->start_date?->toDateString(),
                'ojt_end_date' => $enrollment?->batch?->end_date?->toDateString(),
            ],
            'emergency_contact' => null,
        ]);
    }

    public function store(StoreInfoSheetRequest $request): JsonResponse
    {
        $user = $request->user();
        $sheet = StudentInformationSheet::where('student_id', $user->id)->latest('id')->first();

        // No scaffolded sheet (legacy path): fall back to the active enrollment's
        // batch. Without either, the student has no assigned batch to write against.
        if (! $sheet) {
            $enrollment = $this->activeEnrollment($user->id);

            if (! $enrollment) {
                return response()->json([
                    'message' => 'Your account has no assigned batch yet. Please contact your coordinator.',
                ], 422);
            }

            $sheet = new StudentInformationSheet([
                'student_id' => $user->id,
                'batch_id' => $enrollment->batch_id,
            ]);
        }

        // Once approved the sheet is final — the student can no longer edit it.
        if ($sheet->exists && $sheet->submission_status === 'approved') {
            return response()->json([
                'message' => 'Your information sheet has been approved and can no longer be edited.',
            ], 422);
        }

        $validated = $request->validated();
        $status = $validated['status'];
        unset($validated['status']);

        // ojt_info is `present` (may be empty) — guarantee the NOT-NULL column.
        $validated['ojt_info'] = $validated['ojt_info'] ?? [];

        // Program & Year and the Internship Coordinator are read-only on the
        // form — re-derive them from the intended batch so they can't be spoofed.
        $batch = Batch::with(['program.department', 'coordinator'])->find($sheet->batch_id);
        $validated['academic_info'] = [
            ...($validated['academic_info'] ?? []),
            'program_course' => $batch?->program?->name,
            'department' => $batch?->program?->department?->name,
            'internship_coordinator' => $batch?->coordinator?->name,
        ];

        $sheet->fill([
            ...$validated,
            'submission_status' => $status,
            'submitted_at' => $status === 'submitted' ? now() : null,
            // A fresh student action supersedes any prior rejection.
            'rejection_reason' => null,
        ]);
        $sheet->save();

        return response()->json($sheet);
    }

    /**
     * The coordinator-curated company list backing the "Name of Company"
     * dropdown — the one constrained field on the info sheet.
     */
    public function companies(): JsonResponse
    {
        return response()->json(
            Company::where('is_active', true)->orderBy('name')->get(['id', 'name'])
        );
    }

    /**
     * Download the student's own information sheet as the official MDC PDF.
     */
    public function pdf(Request $request): Response
    {
        $user = $request->user();
        $sheet = StudentInformationSheet::where('student_id', $user->id)->latest('id')->first();

        abort_if($sheet === null, 404, 'You have not started your information sheet yet.');

        return $this->renderInfoSheetPdf($sheet, $user);
    }
}
