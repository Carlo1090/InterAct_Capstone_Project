<?php

namespace App\Http\Controllers\Coordinator;

use App\Http\Controllers\Controller;
use App\Http\Requests\Coordinator\StoreEnrollmentRequest;
use App\Http\Requests\Coordinator\UpdateEnrollmentRequest;
use App\Models\BatchStudent;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EnrollmentController extends Controller
{
    public function enrollableStudents(Request $request): JsonResponse
    {
        $programIds = $request->user()->coordinatorProgramIds();

        if ($request->filled('program_id')) {
            $programIds = $programIds->filter(fn ($id) => $id === $request->integer('program_id'))->values();
        }

        $activelyEnrolledIds = BatchStudent::where('status', 'active')->pluck('student_id');

        $students = User::where('role', 'student')
            ->whereIn('program_id', $programIds)
            ->whereNotIn('id', $activelyEnrolledIds)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'student_id_number', 'program_id']);

        return response()->json($students);
    }

    public function options(Request $request): JsonResponse
    {
        $companies = Company::where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $supervisors = User::where('role', 'supervisor')->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return response()->json([
            'companies' => $companies,
            'supervisors' => $supervisors,
        ]);
    }

    public function store(StoreEnrollmentRequest $request): JsonResponse
    {
        $enrollment = BatchStudent::create([
            ...$request->validated(),
            'status' => 'active',
        ]);

        return response()->json(
            $enrollment->load(['batch.program', 'company', 'supervisor', 'student']),
            201
        );
    }

    public function roster(Request $request): JsonResponse
    {
        $user = $request->user();
        $ownBatches = $user->batchesCoordinated()->orderByDesc('start_date')->get(['id', 'name']);
        $ownBatchIds = $ownBatches->pluck('id');

        $query = BatchStudent::with([
            'student:id,name,email,student_id_number',
            'batch:id,name,program_id',
            'company:id,name',
            'supervisor:id,name,email',
        ])->whereIn('batch_id', $ownBatchIds);

        if ($request->filled('batch_id')) {
            $requestedBatchId = $request->integer('batch_id');
            $query->where('batch_id', $ownBatchIds->contains($requestedBatchId) ? $requestedBatchId : -1);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        $students = $query->orderByDesc('enrolled_at')->get();

        return response()->json([
            'students' => $students,
            'filters' => [
                'batches' => $ownBatches,
                'statuses' => ['active', 'completed', 'dropped'],
            ],
        ]);
    }

    public function update(UpdateEnrollmentRequest $request, BatchStudent $batchStudent): JsonResponse
    {
        $batchStudent->update($request->validated());

        return response()->json($batchStudent->fresh(['batch.program', 'company', 'supervisor', 'student']));
    }
}
