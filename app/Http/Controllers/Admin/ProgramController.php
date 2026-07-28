<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Program;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class ProgramController extends Controller
{
    public function index(): JsonResponse
    {
        // No route anywhere creates/updates/deletes a Program — the 7 programs
        // are fixed at seed time — so this has no invalidation hook to wire up,
        // just a long backstop TTL.
        return response()->json(
            Cache::remember('reference:programs', now()->addDay(), fn () => Program::with('department')->get())
        );
    }

    public function show(Program $program): JsonResponse
    {
        return response()->json($program->load('department'));
    }
}
