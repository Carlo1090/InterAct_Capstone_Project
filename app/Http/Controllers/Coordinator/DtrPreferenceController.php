<?php

namespace App\Http\Controllers\Coordinator;

use App\Http\Controllers\Controller;
use App\Models\BatchStudent;
use App\Models\SystemLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * The coordinator's own DTR preference — set by the admin at account setup,
 * and changeable here afterwards.
 *
 * Switching it OFF is deliberately non-destructive: existing dtr_sessions are
 * kept and simply stop being counted or collected. A programme that pilots
 * the DTR for a semester and drops it does not lose the record of what was
 * already clocked, and switching back on restores the figures intact.
 *
 * Alongside the switch it carries WHY a programme opted out
 * (users.dtr_disabled_reason + dtr_disabled_note). Both are cleared on the way
 * back ON, so a reason can never outlive the decision it explained, and both
 * are optional — a coordinator is never blocked from turning the DTR off just
 * because they have not explained themselves yet.
 */
class DtrPreferenceController extends Controller
{
    /**
     * The action string SystemLog rows are written and read back under.
     *
     * A constant because lastChangedAt() queries on it — the two would
     * otherwise drift the moment the wording changed, and the "last changed"
     * stamp would silently go blank rather than fail.
     */
    private const LOG_ACTION = 'DTR Preference Updated';

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->payload($request->user()));
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'dtr_enabled' => ['required', 'boolean'],
            'dtr_disabled_reason' => ['nullable', Rule::in(array_keys(User::DTR_DISABLED_REASONS))],
            'dtr_disabled_note' => ['nullable', 'string', 'max:255'],
        ]);

        $coordinator = $request->user();
        $enabled = (bool) $validated['dtr_enabled'];

        $coordinator->dtr_enabled = $enabled;

        // The reason explains an OFF switch. Keeping it across a re-enable
        // would leave the record asserting a justification for a state the
        // programme is no longer in.
        $coordinator->dtr_disabled_reason = $enabled ? null : ($validated['dtr_disabled_reason'] ?? null);
        $coordinator->dtr_disabled_note = $enabled
            ? null
            : (filled($validated['dtr_disabled_note'] ?? null) ? $validated['dtr_disabled_note'] : null);

        $coordinator->save();

        SystemLog::record(
            self::LOG_ACTION,
            $enabled
                ? 'Enabled QR/geofence Daily Time Record for their batches.'
                : 'Disabled QR/geofence Daily Time Record for their batches. Existing records were kept.'
                    .($coordinator->dtr_disabled_reason
                        ? ' Reason: '.User::DTR_DISABLED_REASONS[$coordinator->dtr_disabled_reason].'.'
                        : '')
        );

        return response()->json([
            ...$this->payload($coordinator),
            'message' => $enabled
                ? 'Daily Time Record enabled. Supervisors can now generate QR codes for their sites.'
                : 'Daily Time Record disabled. Existing time records are kept but no longer counted.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(User $coordinator): array
    {
        return [
            'dtr_enabled' => (bool) $coordinator->dtr_enabled,
            'dtr_disabled_reason' => $coordinator->dtr_disabled_reason,
            'dtr_disabled_note' => $coordinator->dtr_disabled_note,
            // The vocabulary itself, so the select cannot drift out of step
            // with the Rule::in above by being retyped in the frontend.
            'reason_options' => collect(User::DTR_DISABLED_REASONS)
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values(),
            // How many students the switch currently governs, so the
            // consequence of flipping it is visible before it is flipped.
            'affected_students' => $this->affectedStudentCount($coordinator->id),
            'updated_at' => $this->lastChangedAt($coordinator->id),
        ];
    }

    /**
     * How many interns this switch actually governs.
     *
     * **SUPERVISOR-SUPPORTED BATCHES ONLY, and that clause is the whole point.**
     * `DtrService::runsForEnrollment()` requires both this preference AND a
     * supervisor-supported batch, so an intern on a coordinator-centered cohort
     * can never clock in whatever this switch says. Counting them made the page
     * overstate its own reach — and worse, the turn-off confirmation reads
     * "N active intern(s) will stop seeing it, and clocked hours will no longer
     * count toward their required hours", which was simply untrue of the
     * difference: those interns never had a Daily Time Record to lose.
     *
     * Found 2026-09-08 running a department that hosts BOTH kinds of cohort. It
     * was not hypothetical — the seeded `mdcbalbero` department reported 16
     * against 13 who can actually clock in.
     */
    private function affectedStudentCount(int $coordinatorId): int
    {
        return BatchStudent::where('status', 'active')
            ->whereNull('archived_at')
            ->whereHas('batch', fn ($query) => $query
                ->where('coordinator_id', $coordinatorId)
                ->where('ojt_type', 'supervisor'))
            ->distinct('student_id')
            ->count('student_id');
    }

    /**
     * When this preference was last changed, read back from the audit trail
     * rather than users.updated_at — that column moves on any profile edit and
     * would date the preference to an unrelated change.
     */
    private function lastChangedAt(int $coordinatorId): ?string
    {
        // Parsed explicitly, not read as a Carbon attribute: SystemLog sets
        // $timestamps = false, which switches off the automatic date casting
        // Laravel would otherwise give CREATED_AT — so logged_at comes back as
        // a plain string and calling ->toIso8601String() on it is a 500.
        $loggedAt = SystemLog::where('user_id', $coordinatorId)
            ->where('action', self::LOG_ACTION)
            ->latest('logged_at')
            ->value('logged_at');

        return $loggedAt === null ? null : Carbon::parse($loggedAt)->toIso8601String();
    }
}
