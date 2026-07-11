<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AssignCoordinatorRequest;
use App\Http\Requests\Admin\StoreDepartmentRequest;
use App\Http\Requests\Admin\UpdateDepartmentRequest;
use App\Models\Company;
use App\Models\Department;
use App\Models\SystemLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;

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

        $department->setAttribute('students', User::where('role', 'student')
            ->whereHas('program', fn ($q) => $q->where('department_id', $department->id))
            ->with('program:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'program_id']));

        $department->setAttribute('companies', Company::whereHas(
            'batchStudents.batch.program', fn ($q) => $q->where('department_id', $department->id)
        )->distinct()->orderBy('name')->get(['id', 'name']));

        return response()->json($department);
    }

    public function assignCoordinator(AssignCoordinatorRequest $request, Department $department): JsonResponse
    {
        $department->coordinators()->syncWithoutDetaching([$request->validated('user_id')]);

        $coordinator = User::find($request->validated('user_id'));
        SystemLog::record('Coordinator Assigned', "Assigned {$coordinator->name} to {$department->name}");

        return response()->json($department->coordinators()->orderBy('name')->get(), 201);
    }

    public function removeCoordinator(Department $department, User $coordinator): JsonResponse
    {
        $department->coordinators()->detach($coordinator->id);

        SystemLog::record('Coordinator Removed', "Removed {$coordinator->name} from {$department->name}");

        return response()->json($department->coordinators()->orderBy('name')->get());
    }

    public function store(StoreDepartmentRequest $request): JsonResponse
    {
        $department = Department::create([
            ...$request->validated(),
            'is_active' => $request->boolean('is_active', true),
        ]);

        SystemLog::record('Department Created', "Created department {$department->name} ({$department->code})");

        return response()->json($department, 201);
    }

    public function update(UpdateDepartmentRequest $request, Department $department): JsonResponse
    {
        $department->update($request->validated());

        SystemLog::record('Department Updated', "Updated department {$department->name}");

        return response()->json($department);
    }
}
