<?php

namespace App\Http\Controllers\Coordinator;

use App\Http\Controllers\Controller;
use App\Http\Requests\Coordinator\CreateAccountRequest;
use App\Http\Requests\Coordinator\StoreEnrollmentRequest;
use App\Http\Requests\Coordinator\UpdateEnrollmentRequest;
use App\Models\BatchStudent;
use App\Models\Company;
use App\Models\CompanySupervisor;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;

class EnrollmentController extends Controller
{
    public function enrollableStudents(Request $request): JsonResponse
    {
        $programIds = $request->user()->coordinatorProgramIds();

        if ($request->filled('program_id')) {
            $programIds = $programIds->filter(fn ($id) => $id === $request->integer('program_id'))->values();
        }

        $activelyEnrolledIds = BatchStudent::where('status', 'active')->pluck('student_id');

        $students = User::where('role', 'student')
            ->whereIn('program_id', $programIds)
            ->whereNotIn('id', $activelyEnrolledIds)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'student_id_number', 'program_id']);

        return response()->json($students);
    }

    public function options(Request $request): JsonResponse
    {
        $companies = Company::where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $supervisors = User::where('role', 'supervisor')->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        $programs = Program::whereIn('id', $request->user()->coordinatorProgramIds())
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        return response()->json([
            'companies' => $companies,
            'supervisors' => $supervisors,
            'programs' => $programs,
        ]);
    }

    /**
     * Users → Interns tab: every student whose program is in the coordinator's
     * department scope, REGARDLESS of enrollment, each flagged enrolled/not with
     * their current (active) placement. An optional program_id filter is
     * authorized against scope (403 out of scope), like the report controllers.
     */
    public function interns(Request $request): JsonResponse
    {
        $user = $request->user();
        $programIds = $user->coordinatorProgramIds();

        if ($request->filled('program_id')) {
            $requested = $request->integer('program_id');
            abort_unless($programIds->contains($requested), 403, 'That program is outside your assigned department(s).');
            $programIds = collect([$requested]);
        }

        $students = User::where('role', 'student')
            ->whereIn('program_id', $programIds)
            ->with('program:id,code,name')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'student_id_number', 'program_id']);

        $activeByStudent = BatchStudent::where('status', 'active')
            ->whereIn('student_id', $students->pluck('id'))
            ->with(['batch:id,name,program_id', 'company:id,name', 'supervisor:id,name,email'])
            ->get()
            ->keyBy('student_id');

        $rows = $students->map(function (User $student) use ($activeByStudent) {
            $enrollment = $activeByStudent->get($student->id);

            return [
                'id' => $student->id,
                'name' => $student->name,
                'email' => $student->email,
                'student_id_number' => $student->student_id_number,
                'program' => $student->program,
                'enrolled' => (bool) $enrollment,
                'enrollment' => $enrollment ? [
                    'id' => $enrollment->id,
                    'batch' => $enrollment->batch,
                    'company' => $enrollment->company,
                    'supervisor' => $enrollment->supervisor,
                ] : null,
            ];
        });

        return response()->json($rows->values());
    }

    /**
     * Users → Supervisors tab: the union of (a) supervisors the coordinator
     * created and (b) supervisors attached to companies used by their students.
     * With no creator column on users, a coordinator-created supervisor is only
     * discoverable through the company they were attached to on creation, so
     * both halves collapse to "supervisors attached to any company in the
     * coordinator's company-scope" (companies used by in-scope enrollments plus
     * companies not yet linked to any enrollment). Deduplicated by user, each
     * with the in-scope companies they are attached to.
     */
    public function supervisors(Request $request): JsonResponse
    {
        $scopedCompanyIds = $this->scopedCompanyIds($request->user());

        $links = CompanySupervisor::whereIn('company_id', $scopedCompanyIds)
            ->with(['user:id,name,email,is_active,role', 'company:id,name'])
            ->get()
            ->filter(fn (CompanySupervisor $link) => $link->user && $link->user->role === 'supervisor');

        $supervisors = $links->groupBy('user_id')->map(function (Collection $group) {
            $supervisor = $group->first()->user;

            return [
                'id' => $supervisor->id,
                'name' => $supervisor->name,
                'email' => $supervisor->email,
                'is_active' => (bool) $supervisor->is_active,
                'companies' => $group->map(fn (CompanySupervisor $link) => [
                    'id' => $link->company->id,
                    'name' => $link->company->name,
                    'position' => $link->position,
                ])->values(),
            ];
        })->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();

        return response()->json($supervisors);
    }

    /**
     * Create a student OR supervisor login account (role restricted to those
     * two only). This is NOT enrollment — a created student still needs to be
     * enrolled into a batch (company + supervisor) via store(). Mirrors
     * Admin\UserController::store for the lean create.
     */
    public function createAccount(CreateAccountRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // A student account is only useful in-scope, so a supplied program must
        // belong to the coordinator's department(s).
        if ($validated['role'] === 'student' && ! empty($validated['program_id'])) {
            abort_unless(
                $request->user()->coordinatorProgramIds()->contains((int) $validated['program_id']),
                422,
                'That program is outside your assigned department(s).'
            );
        }

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
            'program_id' => $validated['role'] === 'student' ? ($validated['program_id'] ?? null) : null,
            'student_id_number' => $validated['role'] === 'student' ? ($validated['student_id_number'] ?? null) : null,
            'is_active' => true,
        ]);

        if ($user->isStudent()) {
            StudentProfile::firstOrCreate(
                ['user_id' => $user->id],
                ['student_id_number' => $user->student_id_number],
            );
        }

        return response()->json($user->only(['id', 'name', 'email', 'role', 'is_active']), 201);
    }

    public function store(StoreEnrollmentRequest $request): JsonResponse
    {
        $enrollment = BatchStudent::create([
            ...$request->validated(),
            'status' => 'active',
        ]);

        return response()->json(
            $enrollment->load(['batch.program', 'company', 'supervisor', 'student']),
            201
        );
    }

    public function roster(Request $request): JsonResponse
    {
        $user = $request->user();
        $ownBatches = $user->batchesCoordinated()->orderByDesc('start_date')->get(['id', 'name']);
        $ownBatchIds = $ownBatches->pluck('id');

        $query = BatchStudent::with([
            'student:id,name,email,student_id_number',
            'batch:id,name,program_id',
            'company:id,name',
            'supervisor:id,name,email',
        ])->whereIn('batch_id', $ownBatchIds);

        if ($request->filled('batch_id')) {
            $requestedBatchId = $request->integer('batch_id');
            $query->where('batch_id', $ownBatchIds->contains($requestedBatchId) ? $requestedBatchId : -1);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        $students = $query->orderByDesc('enrolled_at')->get();

        return response()->json([
            'students' => $students,
            'filters' => [
                'batches' => $ownBatches,
                'statuses' => ['active', 'completed', 'dropped'],
            ],
        ]);
    }

    public function update(UpdateEnrollmentRequest $request, BatchStudent $batchStudent): JsonResponse
    {
        $batchStudent->update($request->validated());

        return response()->json($batchStudent->fresh(['batch.program', 'company', 'supervisor', 'student']));
    }

    /**
     * Company IDs a coordinator may see: those referenced by enrollments whose
     * batch program is in their scope, unioned with companies not yet linked to
     * any enrollment. Mirrors CoordinatorCompanyController::scopedCompanyIds so
     * the Users → Supervisors list stays consistent with the Companies page.
     */
    private function scopedCompanyIds(User $coordinator): Collection
    {
        $programIds = $coordinator->coordinatorProgramIds();

        $usedIds = BatchStudent::whereHas('batch', fn ($query) => $query->whereIn('program_id', $programIds))
            ->pluck('company_id')
            ->filter()
            ->unique();

        $unlinkedIds = Company::whereDoesntHave('batchStudents')->pluck('id');

        return $usedIds->merge($unlinkedIds)->unique()->values();
    }
}
