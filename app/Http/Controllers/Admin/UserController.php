<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $users = User::query()
            ->with('program.department')
            ->when($request->filled('role'), fn ($query) => $query->where('role', $request->string('role')))
            ->when($request->filled('program_id'), fn ($query) => $query->where('program_id', $request->integer('program_id')))
            ->when(
                $request->filled('department_id'),
                fn ($query) => $query->whereHas(
                    'program', fn ($q) => $q->where('department_id', $request->integer('department_id'))
                )
            )
            ->when(
                $request->filled('search'),
                fn ($query) => $query->where('name', 'like', '%'.$request->string('search').'%')
            )
            ->orderBy('name')
            ->paginate(20);

        return response()->json($users);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', Rule::in(['student', 'supervisor', 'coordinator', 'admin'])],
            'program_id' => ['nullable', 'exists:programs,id'],
            'student_id_number' => ['nullable', 'string', 'max:30', 'unique:users,student_id_number'],
        ]);

        $user = User::create([
            ...$validated,
            'password' => Hash::make($validated['password']),
            'is_active' => true,
        ]);

        if ($user->isStudent()) {
            StudentProfile::firstOrCreate(
                ['user_id' => $user->id],
                ['student_id_number' => $user->student_id_number],
            );
        }

        return response()->json($user->load('program.department'), 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['sometimes', Rule::in(['student', 'supervisor', 'coordinator', 'admin'])],
            'program_id' => ['sometimes', 'nullable', 'exists:programs,id'],
            'student_id_number' => ['sometimes', 'nullable', 'string', 'max:30', Rule::unique('users', 'student_id_number')->ignore($user->id)],
        ]);

        $user->update($validated);

        return response()->json($user->load('program.department'));
    }

    public function deactivate(User $user): JsonResponse
    {
        $user->update(['is_active' => false]);

        return response()->json(['message' => 'User deactivated.']);
    }

    public function issueTemporaryPassword(User $user): JsonResponse
    {
        $temporaryPassword = Str::password(10);

        $user->update([
            'password' => $temporaryPassword,
            'must_change_password' => true,
        ]);

        return response()->json([
            'message' => 'Temporary password issued.',
            'temporary_password' => $temporaryPassword,
        ]);
    }
}
