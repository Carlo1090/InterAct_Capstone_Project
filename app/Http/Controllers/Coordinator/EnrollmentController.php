<?php

namespace App\Http\Controllers\Coordinator;

use App\Http\Controllers\Concerns\ScopesCoordinatorAccounts;
use App\Http\Controllers\Controller;
use App\Http\Requests\Coordinator\CreateAccountRequest;
use App\Http\Requests\Coordinator\StoreEnrollmentRequest;
use App\Http\Requests\Coordinator\UpdateEnrollmentRequest;
use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\Company;
use App\Models\CompanySupervisor;
use App\Models\JournalEntry;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\SystemLog;
use App\Models\User;
use App\Models\WeeklyActivityLog;
use App\Models\WeeklyLog;
use App\Services\EnrollmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;

class EnrollmentController extends Controller
{
    use ScopesCoordinatorAccounts;

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
        $companies = Company::where('is_active', true)
            // Not scoped to this coordinator: a company can be shared across
            // departments (scopedCompanyIds() narrows the Partner Companies
            // list, but the Enroll/Add-Intern forms let a coordinator place a
            // student at any active company). Each row carries its own
            // resolved login_supervisor below so the read-only preview never
            // needs the (now-scoped) supervisors list to find it.
            ->with('loginSupervisor.user:id,name,username,email')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(function (Company $company) {
                $login = $company->loginSupervisor?->user;

                return [
                    'id' => $company->id,
                    'name' => $company->name,
                    'login_supervisor' => $login ? $login->only(['id', 'name', 'username', 'email']) : null,
                ];
            });

        // Attachable, NOT every supervisor system-wide: a supervisor already on
        // one of this coordinator's own companies, or attached to no company at
        // all yet (a fresh or just-detached account nobody has claimed). This is
        // what the "Attach Existing Supervisor" dropdown on Partner Companies
        // draws from — see ScopesCoordinatorAccounts::attachableSupervisorIds().
        $supervisors = User::where('role', 'supervisor')
            ->whereIn('id', $this->attachableSupervisorIds($request->user()))
            ->orderBy('name')
            ->get(['id', 'name', 'username', 'email']);

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
     * One in-scope student's full detail — profile info, program, and current
     * enrollment. Backs the Interns tab's "View" action.
     */
    public function showIntern(Request $request, User $student): JsonResponse
    {
        abort_unless($student->role === 'student', 404);
        abort_unless(
            $request->user()->coordinatorProgramIds()->contains($student->program_id),
            403,
            'That student is outside your assigned department(s).'
        );

        $student->load(['program.department', 'studentProfile']);

        $enrollment = BatchStudent::where('student_id', $student->id)
            ->where('status', 'active')
            ->with(['batch:id,name', 'company:id,name', 'supervisor:id,name,email'])
            ->first();

        return response()->json([
            'id' => $student->id,
            'name' => $student->name,
            'email' => $student->email,
            'username' => $student->username,
            'avatar_url' => $student->avatar_url,
            'student_id_number' => $student->student_id_number,
            'program' => $student->program,
            'profile' => $student->studentProfile,
            'enrollment' => $enrollment ? [
                'status' => $enrollment->status,
                'batch' => $enrollment->batch,
                'company' => $enrollment->company,
                'supervisor' => $enrollment->supervisor,
            ] : null,
        ]);
    }

    /**
     * PERMANENTLY delete an in-scope student account. This is a guarded
     * hard-delete: it is refused (422) for any account that carries OJT
     * history (journal entries, weekly logs, or weekly activity logs), since
     * deleting the user cascades those records away — the soft-delete
     * (deactivate) path exists precisely to preserve them. Only truly empty
     * accounts (mistakenly created, never used) can be erased here.
     */
    public function destroyAccount(Request $request, User $student): JsonResponse
    {
        abort_unless($student->role === 'student', 404);
        abort_unless(
            $request->user()->coordinatorProgramIds()->contains($student->program_id),
            403,
            'That student is outside your assigned department(s).'
        );

        $hasHistory = JournalEntry::where('student_id', $student->id)->exists()
            || WeeklyLog::where('student_id', $student->id)->exists()
            || WeeklyActivityLog::where('student_id', $student->id)->exists();

        abort_if(
            $hasHistory,
            422,
            'This student has journal or weekly-log records, so their account cannot be permanently deleted. Remove them from their batch to keep their history intact.'
        );

        $name = $student->name;
        // Cascades clear the empty scaffolding (profile, draft info sheet,
        // dropped/inactive enrollment rows) with no OJT history to lose.
        $student->delete();

        SystemLog::record('Account Deleted', "Permanently deleted student account {$name}");

        return response()->json(['deleted' => true]);
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
            ->with(['user:id,name,username,email,is_active,role', 'company:id,name'])
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
                'username' => $supervisor->username,
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
     * One in-scope supervisor's full detail — every company they're attached
     * to (with position) and every in-scope intern currently or previously
     * assigned to them. Backs the Supervisors tab's "View" action, the same
     * role showIntern() plays for a student.
     */
    public function showSupervisor(Request $request, User $supervisor): JsonResponse
    {
        $this->authorizeSupervisorAccount($request->user(), $supervisor);

        $programIds = $request->user()->coordinatorProgramIds();

        $companies = CompanySupervisor::where('user_id', $supervisor->id)
            ->whereIn('company_id', $this->scopedCompanyIds($request->user()))
            ->with('company:id,name')
            ->get()
            ->map(fn (CompanySupervisor $link) => [
                'id' => $link->company->id,
                'name' => $link->company->name,
                'position' => $link->position,
            ])
            ->values();

        $interns = BatchStudent::where('supervisor_id', $supervisor->id)
            ->whereHas('batch', fn ($query) => $query->whereIn('program_id', $programIds))
            ->with(['student:id,name', 'batch:id,name', 'company:id,name'])
            ->orderByDesc('enrolled_at')
            ->get()
            ->map(fn (BatchStudent $enrollment) => [
                'id' => $enrollment->student->id,
                'name' => $enrollment->student->name,
                'status' => $enrollment->status,
                'batch' => $enrollment->batch ? ['id' => $enrollment->batch->id, 'name' => $enrollment->batch->name] : null,
                'company' => $enrollment->company ? ['id' => $enrollment->company->id, 'name' => $enrollment->company->name] : null,
            ])
            ->values();

        return response()->json([
            'id' => $supervisor->id,
            'name' => $supervisor->name,
            'email' => $supervisor->email,
            'username' => $supervisor->username,
            'avatar_url' => $supervisor->avatar_url,
            'is_active' => (bool) $supervisor->is_active,
            'companies' => $companies,
            'interns' => $interns,
        ]);
    }

    /**
     * PERMANENTLY delete an in-scope supervisor account. Mirrors
     * destroyAccount() below in spirit, but what counts as "history" is
     * different and the stakes are higher than they look:
     * batch_students.supervisor_id is a cascadeOnDelete foreign key — it is
     * the authoritative student-to-company linkage (see PROJECT.md) — so
     * deleting a supervisor who was EVER pinned to an enrollment, active or
     * historical, would silently erase those batch_students rows along with
     * them, not merely the supervisor's own login. weekly_logs.supervisor_id
     * is only nullOnDelete, so a review would survive, but it would lose WHO
     * gave the verdict, so that is refused too.
     *
     * Deliberately NOT gated on whether the supervisor is still attached to a
     * company: company_supervisors.user_id is cascadeOnDelete with no history
     * behind it (just "who is currently logged in as this company"), so
     * losing that pointer on delete is exactly what a manual detach already
     * does on purpose — requiring it as a separate first step would add
     * friction with nothing to show for it.
     */
    public function destroySupervisorAccount(Request $request, User $supervisor): JsonResponse
    {
        $this->authorizeSupervisorAccount($request->user(), $supervisor);

        abort_if(
            BatchStudent::where('supervisor_id', $supervisor->id)->exists(),
            422,
            'This supervisor has been assigned to enrolled interns, so their account cannot be permanently deleted.'
        );

        abort_if(
            WeeklyLog::where('supervisor_id', $supervisor->id)->exists(),
            422,
            'This supervisor has reviewed weekly journal logs, so their account cannot be permanently deleted.'
        );

        $name = $supervisor->name;
        // The cascade clears the empty scaffolding (any company_supervisors
        // attachment) with no OJT history behind it to lose.
        $supervisor->delete();

        SystemLog::record('Account Deleted', "Permanently deleted supervisor account {$name}");

        return response()->json(['deleted' => true]);
    }

    /**
     * Scope for the supervisor View/Delete actions: attachableSupervisorIds()
     * rather than scopedSupervisorIds(), because detaching a supervisor from
     * every company — a normal, unforced state, not a prerequisite this
     * action requires — makes them "floating", and scopedSupervisorIds()
     * would then 403 the very account a coordinator is trying to view or
     * delete.
     */
    private function authorizeSupervisorAccount(User $coordinator, User $supervisor): void
    {
        abort_unless($supervisor->role === 'supervisor', 404);
        abort_unless(
            $this->attachableSupervisorIds($coordinator)->contains($supervisor->id),
            403,
            'That supervisor is not attached to any of your companies.'
        );
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
    public function createAccount(CreateAccountRequest $request, EnrollmentService $enrollments): JsonResponse
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

        // Collected as First/Middle/Family Name (matching the Student Information
        // Sheet's field breakdown) but still stored as a single users.name column.
        $name = collect([$validated['first_name'], $validated['middle_name'] ?? null, $validated['last_name']])
            ->filter()
            ->implode(' ');

        $user = User::create([
            'name' => $name,
            'username' => $validated['username'] ?? null,
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
            'program_id' => $validated['role'] === 'student' ? ($validated['program_id'] ?? null) : null,
            'student_id_number' => $validated['role'] === 'student' ? ($validated['student_id_number'] ?? null) : null,
            'is_active' => true,
        ]);

        if ($user->isStudent()) {
            // The UserObserver already auto-created the profile on user create,
            // so set middle_name explicitly (firstOrCreate would no-op on the
            // existing row and never persist it).
            $profile = StudentProfile::firstOrCreate(
                ['user_id' => $user->id],
                ['student_id_number' => $user->student_id_number],
            );
            $profile->update(['middle_name' => $validated['middle_name'] ?? null]);

            $enrollments->scaffoldIntendedSheet($user, $intendedBatch, [
                'first_name' => $validated['first_name'],
                'middle_name' => $validated['middle_name'] ?? null,
                'last_name' => $validated['last_name'],
            ]);
        }

        return response()->json($user->only(['id', 'name', 'username', 'role', 'is_active']), 201);
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

        // The supervisor is tied to the company, never a manual pick — a
        // company change re-derives supervisor_id from the new company's
        // login supervisor (and company_supervisor_id along with it), the
        // same rule EnrollmentService centralizes for every other path.
        if (array_key_exists('company_id', $validated)) {
            $company = Company::findOrFail($validated['company_id']);
            $supervisorId = $company->loginSupervisor?->user_id;
            abort_if($supervisorId === null, 422, 'This company has no supervisor account yet. Add one to the company before assigning it.');

            $validated['supervisor_id'] = $supervisorId;
            $validated['company_supervisor_id'] = CompanySupervisor::where('company_id', $company->id)
                ->where('user_id', $supervisorId)
                ->value('id');
        }

        $batchStudent->update($validated);

        return response()->json($batchStudent->fresh(['batch.program', 'company', 'supervisor', 'student']));
    }
}
