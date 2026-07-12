<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Program;
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
}
