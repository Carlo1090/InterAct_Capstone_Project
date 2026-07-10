<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\SystemLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BatchController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            Batch::with(['program.department', 'coordinator'])
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

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'program_id' => ['required', 'exists:programs,id'],
            'coordinator_id' => ['required', 'exists:users,id'],
            'name' => ['required', 'string', 'max:150'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
            'required_hours' => ['required', 'integer', 'min:1'],
            'working_days_per_week' => ['required', 'integer', 'between:1,7'],
            'daily_reminder_time' => ['required', 'date_format:H:i'],
        ]);

        $batch = Batch::create([
            ...$validated,
            'academic_year' => date('Y', strtotime($validated['start_date'])),
            'semester' => 'Internship',
            'is_active' => true,
        ]);

        SystemLog::record('Batch Created', "Created batch {$batch->name}");

        return response()->json($batch->load(['program.department', 'coordinator']), 201);
    }

    public function update(Request $request, Batch $batch): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'end_date' => ['sometimes', 'date'],
            'coordinator_id' => ['sometimes', 'exists:users,id'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $batch->update($validated);

        SystemLog::record('Batch Updated', "Updated batch {$batch->name}");

        return response()->json($batch->load(['program.department', 'coordinator']));
    }
}
