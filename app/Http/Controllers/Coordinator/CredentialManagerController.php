<?php

namespace App\Http\Controllers\Coordinator;

use App\Http\Controllers\Concerns\ScopesCoordinatorAccounts;
use App\Http\Controllers\Controller;
use App\Models\CompanySupervisor;
use App\Models\SystemLog;
use App\Models\User;
use App\Notifications\NewAccountCredentials;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * Credential Manager — the coordinator's own account-recovery surface, reached
 * from the profile popover rather than from a list page.
 *
 * It REPLACES the per-row "Resend" button that used to sit on Users → Interns.
 * Reissuing someone's password is a critical, irreversible action (their
 * current password stops working the instant it is pressed), and it does not
 * belong as the middle button of a row whose other two actions are "View" and
 * "Delete" — a mis-tap there locks a student out of their own placement. It is
 * also not something a coordinator does while browsing a roster; it is what
 * they do when one specific person reports being unable to sign in, so a
 * deliberate, searched-for destination matches the moment it is used.
 *
 * It covers BOTH populations the coordinator provisions — interns and company
 * supervisors — where Resend only ever reached students. A supervisor login is
 * shared by a whole company and is locked out exactly as easily.
 */
class CredentialManagerController extends Controller
{
    use ScopesCoordinatorAccounts;

    /**
     * Rows are capped because this renders inside the profile popover, not on a
     * page: an unfiltered department can run to hundreds of accounts, and the
     * panel is a search box rather than a roster. `total` reports what the
     * search actually matched, so the UI can say how many are not shown instead
     * of silently truncating.
     */
    public const MAX_ROWS = 40;

    public function index(Request $request): JsonResponse
    {
        $coordinator = $request->user();
        $search = trim((string) $request->string('search'));
        $role = $request->string('role')->toString();

        $rows = collect();

        if ($role !== 'supervisor') {
            $rows = $rows->merge($this->internRows($coordinator, $search));
        }

        if ($role !== 'student') {
            $rows = $rows->merge($this->supervisorRows($coordinator, $search));
        }

        $rows = $rows->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();

        return response()->json([
            'accounts' => $rows->take(self::MAX_ROWS)->values(),
            'total' => $rows->count(),
            'limit' => self::MAX_ROWS,
        ]);
    }

    /**
     * Issue a fresh temporary password, and email it when there is an address
     * to email it to.
     *
     * THE PASSWORD IS ALWAYS RETURNED, and that is the whole reason this action
     * exists rather than the old resend-only one. The documented failure it
     * closes: SMTP reports success but the mail never lands (spam, a mistyped
     * address, a silent drop), so the coordinator was told "Credentials resent"
     * and had nothing to read out — and the one action that surfaced a password
     * was admin-only, so a coordinator meeting that case had to escalate. Here
     * they always leave holding the credential, whatever the mail server did.
     *
     * An account with no email on file is therefore fully supported (a manually
     * created student can have email = null, and Resend simply 422'd them);
     * `emailed` comes back null to say there was nothing to send to, which is
     * a different fact from a delivery that failed.
     */
    public function issue(Request $request, User $user): JsonResponse
    {
        $this->authorizeManagedAccount($request->user(), $user);

        $temporaryPassword = Str::password(12);

        $user->update([
            'password' => $temporaryPassword,
            'must_change_password' => true,
        ]);

        $emailed = null;

        if ($user->email !== null) {
            $emailed = true;

            try {
                $user->notify(new NewAccountCredentials($user->username, $temporaryPassword));
            } catch (Throwable $e) {
                report($e);
                $emailed = false;
            }
        }

        SystemLog::record(
            'Temporary Password Issued',
            "Issued a temporary password for {$user->name} ({$user->role})"
        );

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'role' => $user->role,
            'emailed' => $emailed,
            'temporary_password' => $temporaryPassword,
        ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function internRows(User $coordinator, string $search): Collection
    {
        return User::where('role', 'student')
            ->whereIn('program_id', $coordinator->coordinatorProgramIds())
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('username', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('student_id_number', 'like', "%{$search}%")))
            ->with('program:id,code,name')
            ->orderBy('name')
            ->get(['id', 'name', 'username', 'email', 'is_active', 'student_id_number', 'program_id'])
            ->map(fn (User $student) => [
                'id' => $student->id,
                'name' => $student->name,
                'username' => $student->username,
                'email' => $student->email,
                'role' => 'student',
                'is_active' => (bool) $student->is_active,
                'identifier' => $student->student_id_number,
                'context' => $student->program?->code ?? $student->program?->name,
            ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function supervisorRows(User $coordinator, string $search): Collection
    {
        $supervisorIds = $this->scopedSupervisorIds($coordinator);

        $companiesBySupervisor = CompanySupervisor::whereIn('user_id', $supervisorIds)
            ->whereIn('company_id', $this->scopedCompanyIds($coordinator))
            ->with('company:id,name')
            ->get()
            ->groupBy('user_id');

        return User::where('role', 'supervisor')
            ->whereIn('id', $supervisorIds)
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('username', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->orderBy('name')
            ->get(['id', 'name', 'username', 'email', 'is_active'])
            ->map(function (User $supervisor) use ($companiesBySupervisor) {
                $companies = ($companiesBySupervisor->get($supervisor->id) ?? collect())
                    ->map(fn (CompanySupervisor $link) => $link->company?->name)
                    ->filter()
                    ->unique()
                    ->implode(', ');

                return [
                    'id' => $supervisor->id,
                    'name' => $supervisor->name,
                    'username' => $supervisor->username,
                    'email' => $supervisor->email,
                    'role' => 'supervisor',
                    'is_active' => (bool) $supervisor->is_active,
                    'identifier' => null,
                    'context' => $companies !== '' ? $companies : null,
                ];
            });
    }
}
