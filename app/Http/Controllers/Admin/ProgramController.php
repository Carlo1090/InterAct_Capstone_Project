<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreProgramRequest;
use App\Http\Requests\Admin\UpdateProgramRequest;
use App\Models\Program;
use App\Models\SystemLog;
use Illuminate\Http\JsonResponse;

class ProgramController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Program::with('department')->get());
    }

    public function show(Program $program): JsonResponse
    {
        return response()->json($program->load('department'));
    }

    public function store(StoreProgramRequest $request): JsonResponse
    {
        $program = Program::create([
            ...$request->validated(),
            'is_active' => $request->boolean('is_active', true),
        ]);

        SystemLog::record('Program Created', "Created program {$program->name} ({$program->code})");

        return response()->json($program->load('department'), 201);
    }

    public function update(UpdateProgramRequest $request, Program $program): JsonResponse
    {
        $program->update($request->validated());

        SystemLog::record('Program Updated', "Updated program {$program->name}");

        return response()->json($program->load('department'));
    }
}
