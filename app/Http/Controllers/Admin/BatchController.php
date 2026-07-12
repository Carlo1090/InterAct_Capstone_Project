<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BatchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(
            Batch::with(['program.department', 'coordinator'])
                ->when(
                    $request->filled('department_id'),
                    fn ($query) => $query->whereHas(
                        'program', fn ($q) => $q->where('department_id', $request->integer('department_id'))
                    )
                )
                ->orderByDesc('start_date')
                ->paginate(20)
        );
    }

    public function show(Batch $batch): JsonResponse
    {
        $batch->load(['program.department', 'coordinator', 'journalTemplate']);
        $batch->load(['batchStudents' => fn ($query) => $query
            ->with([
                'student:id,name,email,student_id_number',
                'batch:id,name,program_id',
                'company:id,name',
                'supervisor:id,name,email',
            ])
            ->orderByDesc('enrolled_at')
        ]);

        return response()->json($batch);
    }
}
