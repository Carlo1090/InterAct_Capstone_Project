<?php

namespace App\Http\Controllers\Coordinator;

use App\Http\Controllers\Controller;
use App\Http\Requests\Coordinator\CreateAccountRequest;
use App\Http\Requests\Coordinator\StoreEnrollmentRequest;
use App\Http\Requests\Coordinator\UpdateEnrollmentRequest;
use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\Company;
use App\Models\CompanySupervisor;
use App\Models\Program;
use App\Models\StudentInformationSheet;
use App\Models\StudentProfile;
use App\Models\SystemLog;
use App\Models\User;
use App\Services\EnrollmentService;
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

        // Each supervisor's company_ids let the frontend filter the Supervisor
        // dropdown down to whichever company was picked (a supervisor is always
        // a Company Supervisor via company_supervisors).
        $companyIdsBySupervisor = CompanySupervisor::whereIn('user_id', $supervisors->pluck('id'))
            ->get(['user_id', 'company_id'])
            ->groupBy('user_id')
            ->map(fn (Collection $links) => $links->pluck('company_id')->unique()->values());

        $supervisors = $supervisors->map(function (User $supervisor) use ($companyIdsBySupervisor) {
            $supervisor->setAttribute('company_ids', $companyIdsBySupervisor->get($supervisor->id, collect())->values());

            return $supervisor;
        })->values();

        $programs = Program::whereIn('id', $request->user()->coordinatorProgramIds())
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        // The coordinator's own batches (the set they may enroll into), carrying
        // program_id so the Create-Account form can filter the Batch dropdown by
        // the selected program.
        $batches = $request->user()->batchesCoordinated()
            ->orderByDesc('start_date')
            ->get(['id', 'name', 'program_id']);

        return response()->json([
            'companies' => $companies,
            'supervisors' => $supervisors,
            'programs' => $programs,
            'batches' => $batches,
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
     * with the in-scope companies they are attached to AND the distinct in-scope
     * batches whose students they supervise (so the UI can filter by company and
     * by batch).
     */
    public function supervisors(Request $request): JsonResponse
    {
        $user = $request->user();
        $programIds = $user->coordinatorProgramIds();
        $scopedCompanyIds = $this->scopedCompanyIds($user);

        $links = CompanySupervisor::whereIn('company_id', $scopedCompanyIds)
            ->with(['user:id,name,email,is_active,role', 'company:id,name'])
            ->get()
            ->filter(fn (CompanySupervisor $link) => $link->user && $link->user->role === 'supervisor');

        $supervisorIds = $links->pluck('user_id')->unique();

        // Distinct in-scope batches each supervisor oversees, via batch_students.
        $batchesBySupervisor = BatchStudent::whereIn('supervisor_id', $supervisorIds)
            ->whereHas('batch', fn ($query) => $query->whereIn('program_id', $programIds))
            ->with('batch:id,name')
            ->get()
            ->groupBy('supervisor_id');

        $supervisors = $links->groupBy('user_id')->map(function (Collection $group, $supervisorId) use ($batchesBySupervisor) {
            $supervisor = $group->first()->user;

            $batches = ($batchesBySupervisor->get($supervisorId) ?? collect())
                ->map(fn (BatchStudent $enrollment) => $enrollment->batch)
                ->filter()
                ->unique('id')
                ->map(fn ($batch) => ['id' => $batch->id, 'name' => $batch->name])
                ->values();

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
                'batches' => $batches,
            ];
        })->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();

        return response()->json($supervisors);
    }

    /**
     * Create a student OR supervisor login account (role restricted to those
     * two only), using USERNAME credentials (email is parked, not collected).
     *
     * This is account creation, NOT enrollment. For a student the coordinator
     * pre-sets a program + intended batch, which is recorded as a DRAFT info
     * sheet (its batch_id = the intended batch). The real batch_students
     * placement is realized only when the coordinator ACCEPTS the student's
     * submitted sheet — so a freshly-created student is NOT-ENROLLED.
     */
    public function createAccount(CreateAccountRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $intendedBatch = null;

        if ($validated['role'] === 'student') {
            // A student account is only useful in-scope, so the program must
            // belong to the coordinator's department(s).
            abort_unless(
                $request->user()->coordinatorProgramIds()->contains((int) $validated['program_id']),
                422,
                'That program is outside your assigned department(s).'
            );

            // The intended batch must belong to the pre-set program (it is
            // already constrained to the coordinator's own batches by the request).
            $intendedBatch = Batch::where('id', $validated['batch_id'])
                ->where('program_id', $validated['program_id'])
                ->first();

            abort_unless($intendedBatch !== null, 422, 'The selected batch does not belong to that program.');
        }

        $user = User::create([
            'name' => $validated['name'],
            'username' => $validated['username'],
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

            $this->scaffoldIntendedSheet($user, $intendedBatch);
        }

        return response()->json($user->only(['id', 'name', 'username', 'role', 'is_active']), 201);
    }

    /**
     * Record the coordinator's intended placement as a DRAFT info sheet whose
     * batch_id is the intended batch — the single home for "intended batch
     * before Accept". Program/department/coordinator are pre-filled from the
     * batch so the student's gated info-sheet form opens partly populated; the
     * student supplies the rest and chooses their company from the dropdown.
     */
    private function scaffoldIntendedSheet(User $student, Batch $batch): void
    {
        $batch->loadMissing(['program.department', 'coordinator']);
        [$firstName, $lastName] = array_pad(explode(' ', trim($student->name), 2), 2, '');

        StudentInformationSheet::create([
            'student_id' => $student->id,
            'batch_id' => $batch->id,
            'submission_status' => 'draft',
            'personal_info' => [
                'last_name' => $lastName,
                'first_name' => $firstName,
                'student_id_number' => $student->student_id_number,
            ],
            'academic_info' => [
                'program_course' => $batch->program?->name,
                'department' => $batch->program?->department?->name,
                'internship_coordinator' => $batch->coordinator?->name,
            ],
            'ojt_info' => [],
            'emergency_contact' => null,
        ]);
    }

    /**
     * A previously-dropped row for this exact student+batch is REACTIVATED
     * (status flipped back to active, company/supervisor/division refreshed
     * from this submission) rather than duplicated with a new row — shared with
     * the info-sheet Accept flow via EnrollmentService.
     */
    public function store(StoreEnrollmentRequest $request, EnrollmentService $enrollments): JsonResponse
    {
        $validated = $request->validated();

        // Reused-in-place (any prior row for the pair — dropped, completed,
        // or active) responds 200; a brand-new enrollment row responds 201.
        $wasReused = BatchStudent::where('batch_id', $validated['batch_id'])
            ->where('student_id', $validated['student_id'])
            ->exists();

        $enrollment = $enrollments->enrollOrReactivate(
            $validated['batch_id'],
            $validated['student_id'],
            $validated['company_id'],
            $validated['supervisor_id'],
            $validated['assigned_division'] ?? null,
        );

        $fresh = $enrollment->fresh(['batch.program', 'company', 'supervisor', 'student']);
        SystemLog::record('Student Enrolled', "Enrolled {$fresh->student?->name} into {$fresh->batch?->name}");

        return response()->json($fresh, $wasReused ? 200 : 201);
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
        $validated = $request->validated();

        // A manual company/supervisor edit bypasses EnrollmentService, so
        // re-resolve company_supervisor_id here too or it would go stale.
        if (array_key_exists('supervisor_id', $validated) || array_key_exists('company_id', $validated)) {
            $validated['company_supervisor_id'] = CompanySupervisor::where('company_id', $validated['company_id'] ?? $batchStudent->company_id)
                ->where('user_id', $validated['supervisor_id'] ?? $batchStudent->supervisor_id)
                ->value('id');
        }

        $batchStudent->update($validated);

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
