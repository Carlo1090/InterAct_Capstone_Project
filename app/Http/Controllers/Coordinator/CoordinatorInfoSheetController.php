<?php

namespace App\Http\Controllers\Coordinator;

use App\Http\Controllers\Controller;
use App\Models\BatchStudent;
use App\Models\StudentInformationSheet;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class CoordinatorInfoSheetController extends Controller
{
    /**
     * Read-only list of the coordinator's students and their latest info-sheet
     * submission status. Scoped to students enrolled in the coordinator's
     * department programs.
     */
    public function index(Request $request): JsonResponse
    {
        $studentIds = $this->scopedStudentIds($request->user());

        $students = User::whereIn('id', $studentIds)
            ->where('role', 'student')
            ->with(['program:id,code,name', 'batchEnrollment.company:id,name'])
            ->when(
                $request->filled('search'),
                fn ($query) => $query->where('name', 'like', '%'.$request->string('search').'%')
            )
            ->orderBy('name')
            ->get();

        $latestSheetByStudent = StudentInformationSheet::whereIn('student_id', $students->pluck('id'))
            ->orderByDesc('id')
            ->get(['id', 'student_id', 'submission_status'])
            ->unique('student_id')
            ->keyBy('student_id');

        $rows = $students->map(function (User $student) use ($latestSheetByStudent) {
            $sheet = $latestSheetByStudent->get($student->id);

            return [
                'student_id' => $student->id,
                'name' => $student->name,
                'student_id_number' => $student->student_id_number,
                'program' => $student->program?->code ?? $student->program?->name ?? '',
                'company' => $student->batchEnrollment?->company?->name ?? '',
                'info_sheet_id' => $sheet?->id,
                'submission_status' => $sheet?->submission_status,
            ];
        })->values();

        return response()->json(['students' => $rows]);
    }

    /**
     * Read-only view of one in-scope student's latest info sheet.
     */
    public function show(Request $request, User $student): JsonResponse
    {
        abort_unless($student->role === 'student', 404);
        abort_unless(
            $this->scopedStudentIds($request->user())->contains($student->id),
            403,
            'This student is not in your scope.'
        );

        $sheet = StudentInformationSheet::where('student_id', $student->id)->latest('id')->first();

        return response()->json([
            'student' => $student->only(['id', 'name', 'email']),
            'sheet' => $sheet,
        ]);
    }

    /**
     * Student IDs enrolled (via batch_students) in the coordinator's programs.
     */
    private function scopedStudentIds(User $coordinator): Collection
    {
        $programIds = $coordinator->coordinatorProgramIds();

        return BatchStudent::whereHas('batch', fn ($query) => $query->whereIn('program_id', $programIds))
            ->pluck('student_id')
            ->unique()
            ->values();
    }
}
