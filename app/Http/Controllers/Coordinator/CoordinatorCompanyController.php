<?php

namespace App\Http\Controllers\Coordinator;

use App\Http\Controllers\Controller;
use App\Http\Requests\Coordinator\AddCompanyRepresentativeRequest;
use App\Http\Requests\Coordinator\AttachSupervisorRequest;
use App\Http\Requests\Coordinator\CreateSupervisorRequest;
use App\Http\Requests\Coordinator\StoreCompanyRequest;
use App\Http\Requests\Coordinator\UpdateCompanyRequest;
use App\Models\BatchStudent;
use App\Models\Company;
use App\Models\CompanySupervisor;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Collection;

class CoordinatorCompanyController extends Controller
{
    /**
     * Scoped list: companies used by the coordinator's department students,
     * plus companies not yet linked to any enrollment (which includes any the
     * coordinator just created). See scopedCompanyIds().
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $programIds = $user->coordinatorProgramIds();
        $scopedIds = $this->scopedCompanyIds($user);

        $companies = Company::whereIn('id', $scopedIds)
            ->when(
                $request->filled('search'),
                fn ($query) => $query->where('name', 'like', '%'.$request->string('search').'%')
            )
            ->withCount([
                'batchStudents as active_interns_count' => fn ($query) => $query
                    ->where('status', 'active')
                    ->whereHas('batch', fn ($q) => $q->whereIn('program_id', $programIds)),
            ])
            ->with('supervisors.user:id,name,email')
            ->orderBy('name')
            ->get()
            ->map(fn (Company $company) => [
                ...$company->toArray(),
                'supervisors' => $this->mapSupervisors($company->supervisors)->toArray(),
            ]);

        return response()->json($companies);
    }

    public function show(Request $request, Company $company): JsonResponse
    {
        $this->authorizeCompany($request->user(), $company);

        return response()->json($this->companyPayload($request->user(), $company));
    }

    public function store(StoreCompanyRequest $request): JsonResponse
    {
        $company = Company::create([
            ...$request->validated(),
            'is_active' => $request->boolean('is_active', true),
        ]);

        return response()->json($this->companyPayload($request->user(), $company), 201);
    }

    public function update(UpdateCompanyRequest $request, Company $company): JsonResponse
    {
        $this->authorizeCompany($request->user(), $company);

        $company->update($request->validated());

        return response()->json($this->companyPayload($request->user(), $company));
    }

    /**
     * Attach an existing supervisor-role user to the company with a position.
     * A company may have at most one login-bearing supervisor (the "company
     * account") — re-attaching the same user stays idempotent, but attaching
     * a different one while a login already exists is rejected.
     */
    public function attachSupervisor(AttachSupervisorRequest $request, Company $company): JsonResponse
    {
        $this->authorizeCompany($request->user(), $company);

        $userId = $request->integer('user_id');
        $this->guardSingleLogin($company, $userId);

        CompanySupervisor::firstOrCreate(
            ['company_id' => $company->id, 'user_id' => $userId],
            ['position' => $request->input('position')]
        );

        $this->syncActiveEnrollmentSupervisors($company);

        return response()->json($this->companyPayload($request->user(), $company));
    }

    /**
     * Create a brand-new supervisor account (role forced to supervisor) and
     * attach it to the company. Mirrors Admin\UserController::store. Same
     * one-login-per-company guard as attachSupervisor.
     */
    public function createSupervisor(CreateSupervisorRequest $request, Company $company): JsonResponse
    {
        $this->authorizeCompany($request->user(), $company);
        $this->guardSingleLogin($company);

        $supervisor = User::create([
            'name' => $request->input('name'),
            'email' => $request->input('email'),
            'password' => Hash::make($request->input('password')),
            'role' => 'supervisor',
            'is_active' => true,
        ]);

        CompanySupervisor::create([
            'company_id' => $company->id,
            'user_id' => $supervisor->id,
            'position' => $request->input('position'),
        ]);

        $this->syncActiveEnrollmentSupervisors($company);

        return response()->json($this->companyPayload($request->user(), $company), 201);
    }

    /**
     * Add a purely informational company representative — a named-only
     * company_supervisors row (no user_id, so no login/role). Distinct from
     * attachSupervisor()/createSupervisor(), which always create a
     * login-bearing "company account" row used for OJT weekly-log review.
     * Representatives carry no such capability; they're just a record of who
     * to contact at the company.
     */
    public function addRepresentative(AddCompanyRepresentativeRequest $request, Company $company): JsonResponse
    {
        $this->authorizeCompany($request->user(), $company);

        CompanySupervisor::create([
            'company_id' => $company->id,
            'user_id' => null,
            'name' => $request->input('name'),
            'position' => $request->input('position'),
        ]);

        return response()->json($this->companyPayload($request->user(), $company), 201);
    }

    /**
     * Detach by company_supervisors id (not user id) — a name-only entry has
     * no User to bind to.
     */
    public function detachSupervisor(Request $request, Company $company, CompanySupervisor $companySupervisor): JsonResponse
    {
        $this->authorizeCompany($request->user(), $company);
        abort_unless((int) $companySupervisor->company_id === (int) $company->id, 404);

        $companySupervisor->delete();

        return response()->json($this->companyPayload($request->user(), $company));
    }

    /**
     * Re-point a company's ACTIVE enrollments at its current login supervisor
     * whenever that login changes (attach/create), so batch_students.supervisor_id
     * never goes stale after a company swaps its login account — the common
     * "detach old login, attach new one" flow. Only active rows are touched:
     * completed/dropped enrollments are historical and must keep whoever
     * supervised them. company_supervisor_id (the named individual the student
     * typed on their info sheet) is deliberately left alone — it's independent
     * of who holds the login.
     */
    private function syncActiveEnrollmentSupervisors(Company $company): void
    {
        $loginUserId = $company->loginSupervisor()->value('user_id');

        if ($loginUserId === null) {
            return;
        }

        BatchStudent::where('company_id', $company->id)
            ->where('status', 'active')
            ->update(['supervisor_id' => $loginUserId]);
    }

    /**
     * Reject attaching/creating a second login-bearing supervisor for a
     * company that already has one, unless it's the exact same user
     * (idempotent re-attach).
     */
    private function guardSingleLogin(Company $company, ?int $exceptUserId = null): void
    {
        $existingLogin = $company->loginSupervisor()->with('user:id,name')->first();

        abort_if(
            $existingLogin !== null && $existingLogin->user_id !== $exceptUserId,
            422,
            "This company already has a supervisor login (\"{$existingLogin?->user?->name}\"). Detach it before attaching or creating a different one."
        );
    }

    /**
     * Company IDs a coordinator may see/manage: those referenced by
     * enrollments whose batch program is in their scope, unioned with
     * companies not yet linked to any enrollment.
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

    private function authorizeCompany(User $coordinator, Company $company): void
    {
        abort_unless(
            $this->scopedCompanyIds($coordinator)->contains($company->id),
            403,
            'You do not have access to this company.'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function companyPayload(User $coordinator, Company $company): array
    {
        $programIds = $coordinator->coordinatorProgramIds();

        $company->loadCount([
            'batchStudents as active_interns_count' => fn ($query) => $query
                ->where('status', 'active')
                ->whereHas('batch', fn ($q) => $q->whereIn('program_id', $programIds)),
        ]);
        $company->load('supervisors.user:id,name,email');

        $payload = $company->toArray();
        $payload['supervisors'] = $this->mapSupervisors($company->supervisors)->toArray();

        return $payload;
    }

    /**
     * Normalizes a login-bearing row (display name from user.name) and a
     * name-only row (display name from its own name column) to the same
     * shape, so the frontend doesn't need to special-case either.
     *
     * @param Collection<int, CompanySupervisor> $supervisors
     * @return Collection<int, array<string, mixed>>
     */
    private function mapSupervisors(Collection $supervisors): Collection
    {
        return $supervisors->map(fn (CompanySupervisor $s) => [
            'id' => $s->id,
            'user_id' => $s->user_id,
            'name' => $s->name,
            'position' => $s->position,
            'display_name' => $s->name ?? $s->user?->name,
            'is_login' => $s->user_id !== null,
            'user' => $s->user ? $s->user->only(['id', 'name', 'email']) : null,
        ])->values();
    }
}
