<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DepartmentController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Department::withCount('programs')->get());
    }

    public function show(Department $department): JsonResponse
    {
        $department->loadCount('programs');
        $department->load(['programs' => fn ($query) => $query
            ->withCount([
                'batchStudents as active_interns_count' => fn ($q) => $q->where('status', 'active'),
                'batchStudents as total_interns_count',
            ])
            ->orderBy('name')
        ]);

        $department->setAttribute('active_interns_count', $department->programs->sum('active_interns_count'));

        return response()->json($department);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:20', 'unique:departments,code'],
            'name' => ['required', 'string', 'max:150'],
        ]);

        $department = Department::create([
            ...$validated,
            'is_active' => true,
        ]);

        return response()->json($department, 201);
    }

    public function update(Request $request, Department $department): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
        ]);

        $department->update($validated);

        return response()->json($department);
    }
}
