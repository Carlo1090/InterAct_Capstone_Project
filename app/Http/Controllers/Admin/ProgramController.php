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
        //
        // Cache ->toArray(), never the Eloquent collection: config/cache.php sets
        // `serializable_classes => false`, so models read back out of the database
        // cache store return as __PHP_Incomplete_Class and serialize to garbage.
        // toArray() produces exactly the same JSON this endpoint returned before.
        return response()->json(
            Cache::remember('reference:programs', now()->addDay(), fn () => Program::with('department')->get()->toArray())
        );
    }

    public function show(Program $program): JsonResponse
    {
        return response()->json($program->load('department'));
    }
}
