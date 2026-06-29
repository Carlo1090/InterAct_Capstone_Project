<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    /**
     * List users, optionally filtered by role and/or program.
     * GET /api/admin/users?role=student&program_id=1
     */
    public function index(Request $request): JsonResponse
    {
        $users = User::query()
            ->when($request->filled('role'), fn ($q) => $q->where('role', $request->string('role')))
            ->when($request->filled('program_id'), fn ($q) => $q->where('program_id', $request->integer('program_id')))
            ->with('program')
            ->orderBy('name')
            ->paginate(20);

        return response()->json($users);
    }

    /**
     * Create a new user account. Admins create every account in
     * InternTrack — there is no public self-registration flow for
     * students/supervisors/coordinators.
     * POST /api/admin/users
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = User::create([
            ...$request->safe()->except('password'),
            'password' => Hash::make($request->validated('password')),
            'is_active' => $request->boolean('is_active', true),
        ]);

        // student_profiles auto-creation for role=student happens via
        // UserObserver::created(), not here — see app/Observers/UserObserver.php

        return response()->json($user->load('program'), 201);
    }

    /**
     * View a single user.
     * GET /api/admin/users/{user}
     */
    public function show(User $user): JsonResponse
    {
        return response()->json($user->load(['program', 'studentProfile']));
    }

    /**
     * Update a user's details, role, program, or active status.
     * PATCH /api/admin/users/{user}
     */
    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $user->update($request->validated());

        return response()->json($user->load('program'));
    }

    /**
     * Deactivate a user account. Soft, reversible action (is_active = false)
     * rather than deleting the row, since the user's history (journal
     * entries, weekly logs, etc.) must be preserved.
     * POST /api/admin/users/{user}/deactivate
     */
    public function deactivate(User $user): JsonResponse
    {
        $user->update(['is_active' => false]);

        return response()->json($user->load('program'));
    }

    /**
     * Reactivate a previously deactivated account.
     * POST /api/admin/users/{user}/reactivate
     */
    public function reactivate(User $user): JsonResponse
    {
        $user->update(['is_active' => true]);

        return response()->json($user->load('program'));
    }
}
