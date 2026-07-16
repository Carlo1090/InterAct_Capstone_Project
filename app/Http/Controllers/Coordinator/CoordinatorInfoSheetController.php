<?php

namespace App\Http\Controllers\Coordinator;

use App\Http\Controllers\Concerns\BuildsInfoSheetPdf;
use App\Http\Controllers\Controller;
use App\Http\Requests\Coordinator\RejectInfoSheetRequest;
use App\Models\BatchStudent;
use App\Models\Company;
use App\Models\CompanySupervisor;
use App\Models\Program;
use App\Models\StudentInformationSheet;
use App\Models\SystemLog;
use App\Models\User;
use App\Services\EnrollmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class CoordinatorInfoSheetController extends Controller
{
    use BuildsInfoSheetPdf;

    /**
     * Info sheets whose intended batch is in the coordinator's program scope.
     * This is the Accept/Reject queue — it deliberately includes NOT-yet-
     * enrolled students (whose sheet points at an in-scope batch), which the
     * old enrollment-only scoping could never surface. Filters: status
     * (draft|submitted|approved|rejected), program_id (403 out of scope),
     * search by name.
     */
    public function index(Request $request): JsonResponse
    {
        $coordinator = $request->user();
        $programIds = $coordinator->coordinatorProgramIds();

        if ($request->filled('program_id')) {
            $requested = $request->integer('program_id');
            abort_unless($programIds->contains($requested), 403, 'That program is outside your assigned department(s).');
            $programIds = collect([$requested]);
        }

        $sheets = StudentInformationSheet::whereHas('batch', fn ($query) => $query->whereIn('program_id', $programIds))
            ->when(
                $request->filled('status'),
                fn ($query) => $query->where('submission_status', $request->string('status'))
            )
            ->when(
                $request->filled('search'),
                fn ($query) => $query->whereHas('student', fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'))
            )
            ->with(['student:id,name,student_id_number', 'batch.program:id,code,name'])
            ->orderByRaw("CASE submission_status WHEN 'submitted' THEN 0 WHEN 'rejected' THEN 1 WHEN 'draft' THEN 2 ELSE 3 END")
            ->orderByDesc('submitted_at')
            ->get();

        $rows = $sheets->map(function (StudentInformationSheet $sheet) {
            $program = $sheet->batch?->program;

            return [
                'info_sheet_id' => $sheet->id,
                'student_id' => $sheet->student_id,
                'name' => $sheet->student?->name ?? '',
                'student_id_number' => $sheet->student?->student_id_number,
                'program' => $program?->code ?? $program?->name ?? '',
                'company' => $sheet->ojt_info['host_company'] ?? '',
                'submission_status' => $sheet->submission_status,
                'submitted_at' => $sheet->submitted_at?->toIso8601String(),
            ];
        })->values();

        return response()->json([
            'students' => $rows,
            'programs' => Program::whereIn('id', $coordinator->coordinatorProgramIds())
                ->orderBy('name')
                ->get(['id', 'name', 'code']),
        ]);
    }

    /**
     * One in-scope student's latest info sheet (full read-only document view).
     */
    public function show(Request $request, User $student): JsonResponse
    {
        abort_unless($student->role === 'student', 404);
        $this->assertInScope($request->user(), $student);

        $sheet = StudentInformationSheet::where('student_id', $student->id)->latest('id')->first();

        return response()->json([
            'student' => $student->only(['id', 'name', 'email']),
            'sheet' => $sheet,
        ]);
    }

    /**
     * ACCEPT = enroll. Realizes the intended placement: an active batch_students
     * row for the sheet's pre-set batch + the student's chosen company + that
     * company's supervisor, then marks the sheet approved (which lifts the
     * student's info-sheet gate).
     */
    public function accept(Request $request, User $student, EnrollmentService $enrollments): JsonResponse
    {
        abort_unless($student->role === 'student', 404);
        $this->assertInScope($request->user(), $student);

        $sheet = StudentInformationSheet::where('student_id', $student->id)->latest('id')->first();

        abort_if($sheet === null || $sheet->submission_status !== 'submitted', 422, 'Only a submitted information sheet can be accepted.');

        $companyId = $sheet->ojt_info['company_id'] ?? null;
        $company = $companyId ? Company::find($companyId) : null;
        abort_if($company === null, 422, 'The student has not selected a valid company on their sheet.');

        // Find-or-create the named individual the student actually typed, so
        // it's durably recorded against this enrollment even though the
        // login used to review logs stays the company's shared account.
        $companySupervisorId = $this->resolveNamedSupervisor(
            $company,
            $sheet->ojt_info['supervisor_name'] ?? null,
            $sheet->ojt_info['office_designation'] ?? null,
        )?->id;

        // Always reconcile through the shared service: a re-accept after a
        // revised sheet updates the existing row's company/supervisor in
        // place (never a stale placement, never a second row for the pair).
        // The login supervisor itself is derived from the company by the
        // service, not chosen here — it's tied to the company, not a person.
        $enrollments->enrollOrReactivate(
            $sheet->batch_id,
            $student->id,
            $company->id,
            $sheet->ojt_info['area_assigned'] ?? null,
            $companySupervisorId,
        );

        $sheet->update(['submission_status' => 'approved', 'rejection_reason' => null]);

        SystemLog::record('Info Sheet Accepted', "Enrolled {$student->name} at {$company->name}");

        return response()->json([
            'message' => 'Student enrolled.',
            'sheet' => $sheet->fresh(),
        ]);
    }

    /**
     * REJECT: return the sheet to the student with a reason so they can edit
     * and resubmit. No enrollment happens.
     */
    public function reject(RejectInfoSheetRequest $request, User $student): JsonResponse
    {
        abort_unless($student->role === 'student', 404);
        $this->assertInScope($request->user(), $student);

        $sheet = StudentInformationSheet::where('student_id', $student->id)->latest('id')->first();

        abort_if($sheet === null || $sheet->submission_status !== 'submitted', 422, 'Only a submitted information sheet can be rejected.');

        $sheet->update([
            'submission_status' => 'rejected',
            'rejection_reason' => $request->validated()['reason'],
        ]);

        SystemLog::record('Info Sheet Rejected', "Rejected {$student->name}'s info sheet");

        return response()->json(['message' => 'Sheet returned to the student.', 'sheet' => $sheet->fresh()]);
    }

    /**
     * Download an in-scope student's info sheet as the official MDC PDF.
     */
    public function pdf(Request $request, User $student): Response
    {
        abort_unless($student->role === 'student', 404);
        $this->assertInScope($request->user(), $student);

        $sheet = StudentInformationSheet::where('student_id', $student->id)->latest('id')->first();
        abort_if($sheet === null, 404, 'This student has no information sheet yet.');

        return $this->renderInfoSheetPdf($sheet, $student);
    }

    /**
     * In scope when the student has an info sheet whose batch program is in the
     * coordinator's scope, OR they are enrolled in one of the coordinator's
     * programs (backward-compat for directly-enrolled students).
     */
    private function assertInScope(User $coordinator, User $student): void
    {
        $programIds = $coordinator->coordinatorProgramIds();

        $hasInScopeSheet = StudentInformationSheet::where('student_id', $student->id)
            ->whereHas('batch', fn ($query) => $query->whereIn('program_id', $programIds))
            ->exists();

        $enrolledInScope = BatchStudent::where('student_id', $student->id)
            ->whereHas('batch', fn ($query) => $query->whereIn('program_id', $programIds))
            ->exists();

        abort_unless($hasInScopeSheet || $enrolledInScope, 403, 'This student is not in your scope.');
    }

    /**
     * Find-or-create the company_supervisors row matching the student-typed
     * supervisor name, case-insensitively. Comparison happens in PHP (not
     * SQL) against name ?? user.name to sidestep SQLite/MySQL collation
     * differences. A blank typed name resolves to null (the enrollment then
     * just carries the company's login supervisor with no named individual).
     */
    private function resolveNamedSupervisor(Company $company, ?string $typedName, ?string $position): ?CompanySupervisor
    {
        $typedName = trim((string) $typedName);
        if ($typedName === '') {
            return null;
        }

        $needle = Str::lower($typedName);
        $existing = $company->supervisors()->with('user:id,name')->get()
            ->first(fn (CompanySupervisor $row) => Str::lower(trim($row->name ?? $row->user?->name ?? '')) === $needle);

        return $existing ?? CompanySupervisor::create([
            'company_id' => $company->id,
            'name' => $typedName,
            'position' => $position,
        ]);
    }
}
