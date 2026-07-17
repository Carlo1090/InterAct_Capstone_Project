<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\StudentProfile;
use App\Models\SystemLog;
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
            ->with(['program.department', 'departmentsCoordinated'])
            // This page manages the app's day-to-day accounts, not the (single)
            // admin account itself — the admin never appears in this list.
            ->where('role', '!=', 'admin')
            ->when($request->filled('role'), fn ($query) => $query->where('role', $request->string('role')))
            ->when($request->filled('program_id'), fn ($query) => $query->where('program_id', $request->integer('program_id')))
            ->when(
                $request->filled('department_id'),
                fn ($query) => $query->where(function ($q) use ($request) {
                    $q->whereHas('program', fn ($q2) => $q2->where('department_id', $request->integer('department_id')))
                        ->orWhereHas('departmentsCoordinated', fn ($q2) => $q2->where('departments.id', $request->integer('department_id')));
                })
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
            'role' => [
                'required',
                Rule::in(['student', 'supervisor', 'coordinator', 'admin']),
                function ($attribute, $value, $fail) {
                    if ($value === 'admin' && User::where('role', 'admin')->exists()) {
                        $fail('An admin account already exists.');
                    }
                },
            ],
            'program_id' => ['nullable', 'exists:programs,id'],
            'student_id_number' => ['nullable', 'string', 'max:30', 'unique:users,student_id_number'],
            'department_id' => ['required_if:role,coordinator', 'exists:departments,id'],
        ]);

        $user = User::create([
            ...collect($validated)->except('department_id')->all(),
            'password' => Hash::make($validated['password']),
            'is_active' => true,
        ]);

        if ($user->isStudent()) {
            StudentProfile::firstOrCreate(
                ['user_id' => $user->id],
                ['student_id_number' => $user->student_id_number],
            );
        }

        if ($user->role === 'coordinator') {
            $user->departmentsCoordinated()->attach($validated['department_id']);
        }

        SystemLog::record('User Created', "Created {$user->role} account ({$user->name})");

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

        SystemLog::record('User Updated', "Updated account details for {$user->name}");

        return response()->json($user->load('program.department'));
    }

    public function deactivate(User $user): JsonResponse
    {
        $user->update(['is_active' => false]);

        SystemLog::record('User Deactivated', "Deactivated account for {$user->name}");

        return response()->json(['message' => 'User deactivated.']);
    }

    public function activate(User $user): JsonResponse
    {
        $user->update(['is_active' => true]);

        SystemLog::record('User Activated', "Activated account for {$user->name}");

        return response()->json(['message' => 'User activated.']);
    }

    public function issueTemporaryPassword(User $user): JsonResponse
    {
        $temporaryPassword = Str::password(10);

        $user->update([
            'password' => $temporaryPassword,
            'must_change_password' => true,
        ]);

        SystemLog::record('Temporary Password Issued', "Issued a temporary password for {$user->name}");

        return response()->json([
            'message' => 'Temporary password issued.',
            'temporary_password' => $temporaryPassword,
        ]);
    }
}
