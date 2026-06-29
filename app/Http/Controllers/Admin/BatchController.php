<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreBatchRequest;
use App\Models\Batch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BatchController extends Controller
{
    /**
     * GET /api/admin/batches?program_id=1&is_active=1
     */
    public function index(Request $request): JsonResponse
    {
        $batches = Batch::query()
            ->when($request->filled('program_id'), fn ($q) => $q->where('program_id', $request->integer('program_id')))
            ->when($request->filled('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->with(['program', 'coordinator', 'journalTemplate'])
            ->withCount('batchStudents')
            ->orderByDesc('start_date')
            ->paginate(20);

        return response()->json($batches);
    }

    /**
     * POST /api/admin/batches
     */
    public function store(StoreBatchRequest $request): JsonResponse
    {
        $batch = Batch::create([
            ...$request->validated(),
            'daily_reminder_time' => $request->input('daily_reminder_time', '21:00:00'),
            'is_active' => $request->boolean('is_active', true),
        ]);

        return response()->json($batch->load(['program', 'coordinator', 'journalTemplate']), 201);
    }

    /**
     * GET /api/admin/batches/{batch}
     */
    public function show(Batch $batch): JsonResponse
    {
        return response()->json(
            $batch->load(['program', 'coordinator', 'journalTemplate', 'batchStudents.student'])
        );
    }

    /**
     * PATCH /api/admin/batches/{batch}
     */
    public function update(StoreBatchRequest $request, Batch $batch): JsonResponse
    {
        $batch->update($request->validated());

        return response()->json($batch->load(['program', 'coordinator', 'journalTemplate']));
    }
}
