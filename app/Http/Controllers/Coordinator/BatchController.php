<?php

namespace App\Http\Controllers\Coordinator;

use App\Http\Controllers\Controller;
use App\Http\Requests\Coordinator\StoreBatchRequest;
use App\Http\Requests\Coordinator\UpdateBatchRequest;
use App\Models\Batch;
use App\Models\SystemLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BatchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // interns_count drives the OJT-type lock on the edit form: the type is
        // settled while the roster is empty and frozen once anyone is enrolled
        // (UpdateBatchRequest enforces the same rule server-side).
        $batches = Batch::with(['program.department', 'journalTemplate'])
            ->withCount('batchStudents as interns_count')
            ->where('coordinator_id', $request->user()->id)
            ->orderByDesc('start_date')
            ->get();

        return response()->json($batches);
    }

    public function store(StoreBatchRequest $request): JsonResponse
    {
        $batch = Batch::create([
            ...$request->validated(),
            'coordinator_id' => $request->user()->id,
            'is_active' => true,
        ]);

        SystemLog::record('Batch Created', "Created batch {$batch->name}");

        return response()->json(
            $batch->load(['program.department', 'journalTemplate'])->loadCount('batchStudents as interns_count'),
            201,
        );
    }

    public function update(UpdateBatchRequest $request, Batch $batch): JsonResponse
    {
        $batch->update($request->validated());

        SystemLog::record('Batch Updated', "Updated batch {$batch->name}");

        return response()->json(
            $batch->fresh(['program.department', 'journalTemplate'])->loadCount('batchStudents as interns_count'),
        );
    }
}
