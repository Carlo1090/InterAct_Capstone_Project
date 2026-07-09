<?php

namespace App\Http\Controllers\Coordinator;

use App\Http\Controllers\Controller;
use App\Http\Requests\Coordinator\StoreBatchRequest;
use App\Http\Requests\Coordinator\UpdateBatchRequest;
use App\Models\Batch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BatchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $batches = Batch::with(['program.department', 'journalTemplate'])
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

        return response()->json($batch->load(['program.department', 'journalTemplate']), 201);
    }

    public function update(UpdateBatchRequest $request, Batch $batch): JsonResponse
    {
        $batch->update($request->validated());

        return response()->json($batch->fresh(['program.department', 'journalTemplate']));
    }
}
