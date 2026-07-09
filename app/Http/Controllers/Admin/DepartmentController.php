<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AssignCoordinatorRequest;
use App\Models\Department;
use App\Models\User;
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
        $department->load([
            'programs' => fn ($query) => $query
                ->withCount([
                    'batchStudents as active_interns_count' => fn ($q) => $q->where('status', 'active'),
                    'batchStudents as total_interns_count',
                ])
                ->orderBy('name'),
            'coordinators' => fn ($query) => $query->orderBy('name'),
        ]);

        $department->setAttribute('active_interns_count', $department->programs->sum('active_interns_count'));

        return response()->json($department);
    }

    public function assignCoordinator(AssignCoordinatorRequest $request, Department $department): JsonResponse
    {
        $department->coordinators()->syncWithoutDetaching([$request->validated('user_id')]);

        return response()->json($department->coordinators()->orderBy('name')->get(), 201);
    }

    public function removeCoordinator(Department $department, User $coordinator): JsonResponse
    {
        $department->coordinators()->detach($coordinator->id);

        return response()->json($department->coordinators()->orderBy('name')->get());
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
