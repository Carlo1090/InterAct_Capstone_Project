<?php

namespace App\Http\Controllers\Supervisor;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Supervisor\Concerns\ScopesSupervisorWork;
use App\Models\BatchStudent;
use App\Models\User;
use App\Models\WeeklyLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupervisorInternController extends Controller
{
    use ScopesSupervisorWork;

    /**
     * Students enrolled at companies the authed login represents, each with
     * their program, company, batch, and weekly-log review counts.
     */
    public function index(Request $request): JsonResponse
    {
        $enrollments = $this->supervisedEnrollments($request->user())
            ->with([
                'student:id,name,student_id_number,program_id',
                'student.program:id,code,name',
                'company:id,name',
                'batch:id,name',
            ])
            ->when(
                $request->filled('search'),
                fn ($query) => $query->whereHas('student', fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'))
            )
            ->get();

        // Weekly-log status tallies keyed by student (submitted logs only), scoped
        // to this supervisor's interns.
        $counts = WeeklyLog::whereIn('student_id', $enrollments->pluck('student_id'))
            ->whereNotNull('submitted_at')
            ->select('student_id', 'status', DB::raw('count(*) as total'))
            ->groupBy('student_id', 'status')
            ->get()
            ->groupBy('student_id');

        $interns = $enrollments->map(function (BatchStudent $enrollment) use ($counts) {
            $studentCounts = $counts->get($enrollment->student_id, collect());
            $tally = fn (string $status) => (int) ($studentCounts->firstWhere('status', $status)->total ?? 0);
            $program = $enrollment->student?->program;

            return [
                'student_id' => $enrollment->student_id,
                'name' => $enrollment->student?->name ?? '',
                'student_id_number' => $enrollment->student?->student_id_number,
                'program' => $program?->code ?? $program?->name ?? '',
                'company' => $enrollment->company?->name ?? '',
                'batch' => $enrollment->batch?->name ?? '',
                'status' => $enrollment->status,
                'pending_count' => $tally('pending'),
                'approved_count' => $tally('approved'),
                'returned_count' => $tally('returned'),
            ];
        })
            ->sortBy('name')
            ->values();

        return response()->json(['interns' => $interns]);
    }

    /**
     * One of this supervisor's interns — full detail. Backs the "View" action
     * on the My Interns page. 403 unless the student is enrolled at a company
     * this login represents.
     */
    public function show(Request $request, User $student): JsonResponse
    {
        abort_unless($student->role === 'student', 404);

        $supervisor = $request->user();
        abort_unless(
            $this->supervisedStudentIds($supervisor)->contains($student->id),
            403,
            'This student is not one of your interns.'
        );

        $student->load(['program', 'studentProfile']);

        $enrollment = $this->supervisedEnrollments($supervisor)
            ->where('student_id', $student->id)
            ->with(['batch:id,name', 'company:id,name'])
            ->first();

        return response()->json([
            'id' => $student->id,
            'name' => $student->name,
            'email' => $student->email,
            'avatar_url' => $student->avatar_url,
            'student_id_number' => $student->student_id_number,
            'program' => $student->program,
            'profile' => $student->studentProfile,
            'enrollment' => $enrollment ? [
                'status' => $enrollment->status,
                'batch' => $enrollment->batch,
                'company' => $enrollment->company,
            ] : null,
        ]);
    }
}
