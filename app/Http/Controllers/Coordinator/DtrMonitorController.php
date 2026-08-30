<?php

namespace App\Http\Controllers\Coordinator;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\CompanyGeofence;
use App\Models\DtrSession;
use App\Models\Program;
use App\Services\DtrService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Read-only coordinator view over the Daily Time Record.
 *
 * Same posture as CoordinatorWeeklyJournalController: the coordinator
 * observes, the supervisor corrects. There are deliberately no adjust/void
 * actions here — the person who signs off on an intern's hours should be the
 * person who was at the workplace.
 *
 * The one thing a coordinator genuinely needs to audit is where the fences
 * are. A supervisor who generates a QR code at home anchors the geofence to
 * their house, and nothing in the automated flow would notice; sites() lists
 * the coordinates so a coordinator can check them against the company address.
 */
class DtrMonitorController extends Controller
{
    public function __construct(private readonly DtrService $dtr) {}

    /**
     * Hours banked against hours required, per in-scope intern on a
     * DTR-enabled batch.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'program_id' => ['nullable', 'integer'],
        ]);

        $scopedProgramIds = $request->user()->coordinatorProgramIds();
        $programIds = $scopedProgramIds;

        if (! empty($validated['program_id'])) {
            $requested = (int) $validated['program_id'];
            abort_unless($scopedProgramIds->contains($requested), 403, 'That program is outside your assigned department(s).');
            $programIds = collect([$requested]);
        }

        $enrollments = BatchStudent::with([
            'student:id,name,student_id_number',
            // requiredHours() reads student_profiles.total_hours_required as
            // the per-student override. Without this eager load it lazy-loads
            // one profile per intern — a second N+1 hiding behind the first.
            'student.studentProfile:id,user_id,total_hours_required',
            'batch:id,name,program_id,coordinator_id,required_hours',
            'batch.coordinator:id,dtr_enabled',
            'company:id,name',
        ])
            ->whereIn('status', ['active', 'completed'])
            ->whereNull('archived_at')
            ->whereHas('batch', fn ($query) => $query->whereIn('program_id', $programIds))
            // Only batches whose coordinator opted into DTR have hours to
            // report at all — AND only supervisor-supported ones, mirroring
            // DtrService::runsForEnrollment(). Without the second clause a
            // coordinator-centered cohort was listed here at zero hours and no
            // sites, which reads as "these interns never clock in" rather than
            // "the Daily Time Record does not apply to them".
            ->whereHas('batch', fn ($query) => $query
                ->where('ojt_type', Batch::OJT_TYPE_SUPERVISOR)
                ->whereHas('coordinator', fn ($inner) => $inner->where('dtr_enabled', true)))
            ->get();

        // Every session figure for the whole page in ONE grouped query.
        // Done per-status rather than through DtrService's per-student helpers
        // because those cost three queries per intern — 300 round trips for a
        // 100-intern department, on a free-tier instance that cold-starts.
        $tallies = $this->sessionTallies($enrollments);

        $rows = $enrollments->map(function (BatchStudent $enrollment) use ($tallies) {
            $key = $enrollment->student_id.':'.$enrollment->batch_id;
            $tally = $tallies[$key] ?? ['minutes' => 0, 'open' => 0, 'flagged' => 0];

            $minutes = $tally['minutes'];
            $required = $this->dtr->requiredHours($enrollment);

            return [
                'student_id' => $enrollment->student_id,
                'student_name' => $enrollment->student?->name,
                'student_id_number' => $enrollment->student?->student_id_number,
                'company' => $enrollment->company?->name,
                'batch' => $enrollment->batch?->name,
                'hours_completed' => round($minutes / 60, 1),
                'hours_required' => $required,
                'hours_percent' => $required > 0
                    ? max(0, min(100, (int) round(($minutes / 60) / $required * 100)))
                    : null,
                'open_sessions' => $tally['open'],
                'flagged_sessions' => $tally['flagged'],
            ];
        })->sortBy('student_name')->values();

        return response()->json([
            'rows' => $rows,
            'programs' => Program::whereIn('id', $scopedProgramIds)
                ->orderBy('name')
                ->get(['id', 'name', 'code']),
        ]);
    }

    /**
     * Banked minutes, open count and flagged count for every enrollment on the
     * page, in one grouped query, keyed "studentId:batchId".
     *
     * @param  Collection<int, BatchStudent>  $enrollments
     * @return array<string, array{minutes: int, open: int, flagged: int}>
     */
    private function sessionTallies($enrollments): array
    {
        if ($enrollments->isEmpty()) {
            return [];
        }

        $rows = DtrSession::query()
            ->selectRaw('student_id, batch_id, status, COUNT(*) as session_count, COALESCE(SUM(minutes_worked), 0) as total_minutes')
            ->whereIn('student_id', $enrollments->pluck('student_id')->unique()->values())
            ->whereIn('batch_id', $enrollments->pluck('batch_id')->unique()->values())
            ->groupBy('student_id', 'batch_id', 'status')
            ->get();

        $tallies = [];

        foreach ($rows as $row) {
            $key = $row->student_id.':'.$row->batch_id;
            $tallies[$key] ??= ['minutes' => 0, 'open' => 0, 'flagged' => 0];

            // Only 'closed' minutes count toward required hours — the same rule
            // DtrService::minutesCompleted() applies, kept in step via the
            // shared COUNTED_STATUSES constant rather than a repeated literal.
            if (in_array($row->status, DtrSession::COUNTED_STATUSES, true)) {
                $tallies[$key]['minutes'] += (int) $row->total_minutes;
            }

            if ($row->status === 'open') {
                $tallies[$key]['open'] += (int) $row->session_count;
            }

            if ($row->status === 'flagged') {
                $tallies[$key]['flagged'] += (int) $row->session_count;
            }
        }

        return $tallies;
    }

    /**
     * Every clock-in site hosting an in-scope intern, with its coordinates
     * and a maps link — the coordinator's check that a fence is where the
     * company actually is.
     */
    public function sites(Request $request): JsonResponse
    {
        $programIds = $request->user()->coordinatorProgramIds();

        $companyIds = BatchStudent::whereIn('status', ['active', 'completed'])
            ->whereNull('archived_at')
            ->whereHas('batch', fn ($query) => $query->whereIn('program_id', $programIds))
            ->pluck('company_id')
            ->unique()
            ->values();

        $sites = CompanyGeofence::with('company:id,name,address')
            ->whereIn('company_id', $companyIds)
            ->orderBy('label')
            ->get()
            ->map(fn (CompanyGeofence $geofence) => [
                'id' => $geofence->id,
                'label' => $geofence->label,
                'company' => $geofence->company?->name,
                'company_address' => $geofence->company?->address,
                'latitude' => $geofence->latitude,
                'longitude' => $geofence->longitude,
                'radius_meters' => $geofence->radius_meters,
                'captured_accuracy' => $geofence->captured_accuracy,
                'accuracy_is_poor' => $geofence->captured_accuracy !== null
                    && $geofence->captured_accuracy > CompanyGeofence::POOR_ACCURACY_METRES,
                'is_active' => $geofence->is_active,
                'map_url' => "https://www.google.com/maps/search/?api=1&query={$geofence->latitude},{$geofence->longitude}",
                'created_at' => $geofence->created_at?->toIso8601String(),
            ]);

        return response()->json(['sites' => $sites]);
    }
}
