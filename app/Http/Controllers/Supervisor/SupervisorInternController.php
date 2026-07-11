<?php

namespace App\Http\Controllers\Supervisor;

use App\Http\Controllers\Controller;
use App\Models\BatchStudent;
use App\Models\WeeklyLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupervisorInternController extends Controller
{
    /**
     * Students enrolled with the authed supervisor (batch_students.supervisor_id),
     * each with their program, company, batch, and weekly-log review counts.
     */
    public function index(Request $request): JsonResponse
    {
        $supervisorId = $request->user()->id;

        $enrollments = BatchStudent::where('supervisor_id', $supervisorId)
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
}
