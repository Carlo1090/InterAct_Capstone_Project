<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreProgramRequest;
use App\Http\Requests\Admin\UpdateProgramRequest;
use App\Models\Department;
use App\Models\Program;
use App\Models\SystemLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class ProgramController extends Controller
{
    public function index(): JsonResponse
    {
        // Cache ->toArray(), never the Eloquent collection: config/cache.php sets
        // `serializable_classes => false`, so models read back out of the database
        // cache store return as __PHP_Incomplete_Class and serialize to garbage.
        // toArray() produces exactly the same JSON this endpoint returned before.
        //
        // Invalidated at this controller's own write points via forgetCachesFor().
        return response()->json(
            Cache::remember('reference:programs', now()->addDay(), fn () => Program::with('department')->get()->toArray())
        );
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

        $program->load('department');

        SystemLog::record('Program Created', "Created program {$program->name} ({$program->code}) under {$program->department->name}");

        $this->forgetCachesFor($program->department_id);

        return response()->json($program, 201);
    }

    public function update(UpdateProgramRequest $request, Program $program): JsonResponse
    {
        $program->update($request->validated());

        $program->load('department');

        SystemLog::record('Program Updated', "Updated program {$program->name} ({$program->code})");

        $this->forgetCachesFor($program->department_id);

        return response()->json($program);
    }

    /**
     * Drop every cached list a program appears in.
     *
     * THE COORDINATOR CACHE IS THE LOAD-BEARING ONE, and it is why this method
     * exists rather than a bare Cache::forget() at each call site.
     * `User::coordinatorProgramIds()` resolves to EVERY program in the
     * coordinator's assigned department and caches that for a **day** — so
     * without this, a program added to their department stays invisible to them
     * (no batch can be created for it, and it is missing from every scoped page)
     * for up to 24 hours, with the database perfectly correct the whole time.
     * That is precisely the class of bug DepartmentProgramSeeder documents
     * hitting on 2026-09-08, and it does not reproduce under
     * `migrate:fresh --seed` because that drops the `cache` table.
     *
     * `reference:departments` is included because its rows carry
     * `programs_count`, which a create moves.
     */
    private function forgetCachesFor(int $departmentId): void
    {
        Cache::forget('reference:programs');
        Cache::forget('reference:departments');

        Department::find($departmentId)?->coordinators()->pluck('users.id')
            ->each(fn (int $coordinatorId) => Cache::forget("coordinator-program-ids:{$coordinatorId}"));
    }
}
