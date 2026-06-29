<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreProgramRequest;
use App\Models\Program;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProgramController extends Controller
{
    /**
     * GET /api/admin/programs?department_id=1
     */
    public function index(Request $request): JsonResponse
    {
        $programs = Program::query()
            ->when($request->filled('department_id'), fn ($q) => $q->where('department_id', $request->integer('department_id')))
            ->with('department')
            ->orderBy('name')
            ->get();

        return response()->json($programs);
    }

    /**
     * POST /api/admin/programs
     */
    public function store(StoreProgramRequest $request): JsonResponse
    {
        $program = Program::create([
            ...$request->validated(),
            'is_active' => $request->boolean('is_active', true),
        ]);

        return response()->json($program->load('department'), 201);
    }

    /**
     * GET /api/admin/programs/{program}
     */
    public function show(Program $program): JsonResponse
    {
        return response()->json($program->load('department'));
    }
}
