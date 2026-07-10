<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Program;
use App\Models\SystemLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProgramController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Program::with('department')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'department_id' => ['required', 'exists:departments,id'],
            'name' => ['required', 'string', 'max:200'],
            'code' => [
                'required',
                'string',
                'max:20',
                Rule::unique('programs', 'code')->where('department_id', $request->input('department_id')),
            ],
        ]);

        $program = Program::create([
            ...$validated,
            'is_active' => true,
        ]);

        SystemLog::record('Program Created', "Created program {$program->name} ({$program->code})");

        return response()->json($program->load('department'), 201);
    }
}
