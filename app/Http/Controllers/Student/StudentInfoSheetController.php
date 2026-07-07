<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Student\Concerns\ResolvesStudentEnrollment;
use App\Http\Requests\Student\StoreInfoSheetRequest;
use App\Models\StudentInformationSheet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentInfoSheetController extends Controller
{
    use ResolvesStudentEnrollment;

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $sheet = StudentInformationSheet::where('student_id', $user->id)->latest('id')->first();

        if ($sheet) {
            return response()->json($sheet);
        }

        $enrollment = $this->activeEnrollment($user->id);
        $profile = $user->studentProfile;
        [$firstName, $lastName] = array_pad(explode(' ', $user->name, 2), 2, '');

        return response()->json([
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
                'division_assigned' => $enrollment?->assigned_division,
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
        $enrollment = $this->activeEnrollment($user->id);

        if (! $enrollment) {
            return response()->json(['message' => 'You are not currently enrolled in an active OJT batch.'], 422);
        }

        $validated = $request->validated();
        $status = $validated['status'];
        unset($validated['status']);

        $sheet = StudentInformationSheet::updateOrCreate(
            ['student_id' => $user->id, 'batch_id' => $enrollment->batch_id],
            [
                ...$validated,
                'submission_status' => $status,
                'submitted_at' => $status === 'submitted' ? now() : null,
            ]
        );

        return response()->json($sheet);
    }
}
