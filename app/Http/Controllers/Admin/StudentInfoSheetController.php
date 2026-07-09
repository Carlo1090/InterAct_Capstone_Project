<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Student\Concerns\ResolvesStudentEnrollment;
use App\Models\BatchStudent;
use App\Models\StudentInformationSheet;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentInfoSheetController extends Controller
{
    use ResolvesStudentEnrollment;

    public function index(Request $request): JsonResponse
    {
        $students = User::query()
            ->where('role', 'student')
            ->with(['program.department', 'batchEnrollment.company'])
            ->when(
                $request->filled('search'),
                fn ($query) => $query->where('name', 'like', '%'.$request->string('search').'%')
            )
            ->when(
                $request->filled('department_id'),
                fn ($query) => $query->whereHas(
                    'program',
                    fn ($q) => $q->where('department_id', $request->integer('department_id'))
                )
            )
            ->when($request->query('status') === 'submitted', fn ($query) => $query->whereHas(
                'studentInformationSheets',
                fn ($q) => $q->where('submission_status', 'submitted')
            ))
            ->when($request->query('status') === 'draft', fn ($query) => $query->whereHas(
                'studentInformationSheets',
                fn ($q) => $q->where('submission_status', 'draft')
            ))
            ->when(
                $request->query('status') === 'not-started',
                fn ($query) => $query->whereDoesntHave('studentInformationSheets')
            )
            ->orderBy('name')
            ->paginate(20);

        $studentIds = $students->getCollection()->pluck('id');

        $latestSheetByStudent = StudentInformationSheet::whereIn('student_id', $studentIds)
            ->orderByDesc('id')
            ->get(['id', 'student_id', 'submission_status'])
            ->unique('student_id')
            ->keyBy('student_id');

        $students->getCollection()->transform(function (User $student) use ($latestSheetByStudent) {
            $sheet = $latestSheetByStudent->get($student->id);
            $student->setAttribute('info_sheet_id', $sheet?->id);
            $student->setAttribute('submission_status', $sheet?->submission_status);

            return $student;
        });

        return response()->json($students);
    }

    public function show(User $student): JsonResponse
    {
        abort_unless($student->role === 'student', 404);

        $sheet = StudentInformationSheet::where('student_id', $student->id)->latest('id')->first();
        $studentSummary = $student->only(['id', 'name', 'email']);

        if ($sheet) {
            return response()->json([
                ...$sheet->toArray(),
                'student' => $studentSummary,
            ]);
        }

        $enrollment = $this->activeEnrollment($student->id);

        return response()->json([
            ...$this->defaultInfoSheetPayload($student, $enrollment),
            'student' => $studentSummary,
        ]);
    }

    /**
     * Mirrors the "not started yet" shape from
     * Student\StudentInfoSheetController::show(), pre-filled from the
     * student's profile and current enrollment where possible.
     *
     * @return array<string, mixed>
     */
    private function defaultInfoSheetPayload(User $student, ?BatchStudent $enrollment): array
    {
        $profile = $student->studentProfile;
        [$firstName, $lastName] = array_pad(explode(' ', $student->name, 2), 2, '');

        return [
            'id' => null,
            'submission_status' => null,
            'submitted_at' => null,
            'personal_info' => [
                'last_name' => $lastName,
                'first_name' => $firstName,
                'middle_name' => $profile?->middle_name,
                'parent_guardian_name' => null,
                'date_of_birth' => $profile?->date_of_birth?->toDateString(),
                'sex' => $profile?->sex,
                'home_address' => $profile?->home_address,
                'contact_number' => $profile?->contact_number,
                'email' => $student->email,
                'student_id_number' => $student->student_id_number,
            ],
            'academic_info' => [
                'program_course' => $student->program?->name,
                'year_level' => $profile?->year_level,
                'department' => $student->program?->department?->name,
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
                'division_assigned' => $enrollment?->assigned_division,
                'intern_duty_schedule' => null,
                'ojt_start_date' => $enrollment?->batch?->start_date?->toDateString(),
                'ojt_end_date' => $enrollment?->batch?->end_date?->toDateString(),
            ],
            'emergency_contact' => null,
        ];
    }
}
