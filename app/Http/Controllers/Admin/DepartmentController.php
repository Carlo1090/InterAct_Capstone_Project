<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreDepartmentRequest;
use App\Models\Department;
use Illuminate\Http\JsonResponse;

class DepartmentController extends Controller
{
    /**
     * GET /api/admin/departments
     */
    public function index(): JsonResponse
    {
        return response()->json(
            Department::withCount('programs')->orderBy('name')->get()
        );
    }

    /**
     * POST /api/admin/departments
     */
    public function store(StoreDepartmentRequest $request): JsonResponse
    {
        $department = Department::create([
            ...$request->validated(),
            'is_active' => $request->boolean('is_active', true),
        ]);

        return response()->json($department, 201);
    }

    /**
     * GET /api/admin/departments/{department}
     */
    public function show(Department $department): JsonResponse
    {
        return response()->json($department->load('programs'));
    }
}
